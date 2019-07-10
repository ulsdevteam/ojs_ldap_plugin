<?php

/**
 * @file plugins/generic/ldap/LDAPAuthPlugin.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Copyright (c) 2019 Shem Pasamba
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class LDAPAuthPlugin
 * @ingroup plugins_generic_ldap
 *
 * @brief LDAP authentication plugin.
 *
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class LDAPAuthPlugin extends GenericPlugin {
	// @@@ TODO: Is there a way to disable delete and upgrade actions
	// when the user does not have permission to disable?

	// @@@ TODO: The profile password tab should just be hidden
	// completely when the plugin is enabled.

	/** @var int */
	var $_contextId;

	/** @var bool */
	var $_globallyEnabled;

	/**
	 * @copydoc Plugin::__construct()
	 */
	function __construct() {
		parent::__construct();
		$this->_contextId = $this->getCurrentContextId();
		$this->_globallyEnabled = $this->getSetting(0, 'enabled');
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();
		if ($success && $this->getEnabled()) {
			// Register pages to handle login.
			HookRegistry::register(
				'LoadHandler',
				array($this, 'handleRequest')
			);
		}
		return $success;
	}

	/**
	 * @copydoc LazyLoadPlugin::getName()
	 */
	function getName() {
		return 'LDAPAuthPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.ldap.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.ldap.description');
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	function isSitePlugin() {
		return true;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				AppLocale::requireComponents(
					LOCALE_COMPONENT_APP_COMMON,
					LOCALE_COMPONENT_PKP_MANAGER
				);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->register_function(
					'plugin_url',
					array($this, 'smartyPluginUrl')
				);

				$this->import('LDAPSettingsForm');
				$form = new LDAPSettingsForm(
					$this,
					$this->_contextId
				);

				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * @copydoc Plugin::getSetting()
	 */
	function getSetting($contextId, $name) {
		if ($this->_globallyEnabled) {
			return parent::getSetting(0, $name);
		} else {
			return parent::getSetting($contextId, $name);
		}
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		// Don’t allow settings unless enabled in this context.
		if (!$this->getEnabled() || !$this->getCanDisable()) {
			return parent::getActions($request, $verb);
		}

		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url(
							$request,
							null,
							null,
							'manage',
							null,
							array(
								'verb' => 'settings',
								'plugin' => $this->getName(),
								'category' => 'generic'
							)
						),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			),
			parent::getActions($request, $verb)
		);
	}


	//
	// Public methods required to support lazy load.
	//
	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable() {
		return !$this->_globallyEnabled || $this->_contextId == 0;
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	function getCanDisable() {
		return !$this->_globallyEnabled || $this->_contextId == 0;
	}

	/**
	 * @copydoc LazyLoadPlugin::setEnabled()
	 */
	function setEnabled($enabled) {
		$this->updateSetting($this->_contextId, 'enabled', $enabled, 'bool');
	}


	//
	// Callback handler
	// 
	/**
	 * Hook callback: register pages for each login method.
	 * This URL is of the form: ldap/{$ldaprequest}
	 * @see PKPPageRouter::route()
	 */
	function handleRequest($hookName, $params) {
		$page = $params[0];
		$op = $params[1];
		if ($this->getEnabled()
			&& ($page == 'ldap'
				|| ($page == 'login'
					&& array_search(
						$op,
						array(
							'changePassword',
							'lostPassword',
							'requestResetPassword',
							'savePassword',
							'signIn',
							'signOut',
						)
					))
				|| ($page == 'user'
					&& array_search(
						$op,
						array(
							'activateUser',
							'validate',
						)
					)
				)
			)
		) {
			$this->import('pages/LDAPHandler');
			define('HANDLER_CLASS', 'LDAPHandler');
			define('LDAP_PLUGIN_NAME', $this->getName());
			return true;
		}
		return false;
	}

	/**
	 * Get a LDAP resource for a server URI
	 * @param $server string the server URI
	 * @return resource|false
	 */
	function _getLdapResource($server) {
		$ldapConn = ldap_connect($server);
		if ($ldapConn) {
			// set settings for making it work with AD
			ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
			ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

			// use tls if not using ldaps
			if (!preg_match('/ldaps.*636/', $server)) {
				ldap_start_tls($ldapConn);
			}
			return $ldapConn;
		}
		return false;
	}
}
