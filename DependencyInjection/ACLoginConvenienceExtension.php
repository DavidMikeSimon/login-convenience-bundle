<?php

namespace AC\LoginConvenienceBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

class ACLoginConvenienceExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $serviceLoader = new Loader\YamlFileLoader(
            $container, new FileLocator(__DIR__.'/../Resources/config')
        );
        $serviceLoader->load('services.yml');
    }

    /**
     * Fill out the security and fp_open_id config sections.
     *
     * The main purpose of the bundle is to let app code avoid having to worry
     * about SecurityBundle or OpenID stuff directly. Instead, the app just
     * specifies the much simpler ac_login_convenience config section.
     */
    public function prepend(ContainerBuilder $container)
    {
        $config = $this->processConfiguration(
            new Configuration(),
            $container->getExtensionConfig($this->getAlias())
        );

        # resolveValue replaces %var% refs with real values from parameters.yml
        $config = $container->getParameterBag()->resolveValue($config);
        $this->setParameters($container, $config);

        $userClass = $container->getParameter('ac_login_convenience.user_model_class');
        $identityClass = $container->getParameter('ac_login_convenience.openid_identity_class');
        $dbDriver = $container->getParameter('ac_login_convenience.db_driver');
        $securedPaths = $container->getParameter('ac_login_convenience.secured_paths');
        $dummyMode = $container->getParameter('ac_login_convenience.dummy_mode');
        $apiKeys = $container->getParameter('ac_login_convenience.api_keys');

        # Generate the big nasty Symfony security config section
        $secConf = $this->generateSecurityConf($securedPaths, $dummyMode, $apiKeys);

        # Set up our user database for authentication against
        $userProviderKey = $dbDriver;
        if ($dbDriver == "orm") { $userProviderKey = "entity"; }
        $secConf["providers"]["app_users"][$userProviderKey] = [
            "class" => $userClass,
            "property" => "email"
        ];

        $container->prependExtensionConfig("security", $secConf);

        $container->prependExtensionConfig("fp_open_id", [
            "db_driver" => $dbDriver,
            "identity_class" => $identityClass
        ]);
    }

    # See README.md for the meanings of these settings
    private function setParameters($container, $config)
    {
        $container->setParameter(
            'ac_login_convenience.dummy_mode',
            $config['dummy_mode']
        );

        $container->setParameter(
            'ac_login_convenience.api_keys',
            $config['api_keys']
        );

        $container->setParameter(
            'ac_login_convenience.trusted_openid_providers',
            $config['trusted_openid_providers']
        );

        $container->setParameter(
            'ac_login_convenience.secured_paths',
            $config['secured_paths']
        );

        $container->setParameter(
            'ac_login_convenience.db_driver',
            $config['db_driver']
        );

        # Figure out how we're persisting User and Identity data
        # This will be used by Symfony security, FpOpenIdBundle, and this bundle
        $persistenceService = null;
        if ($config["db_driver"] == "orm") {
            $persistenceService = "doctrine";
        } elseif ($config["db_driver"] == "mongodb") {
            $persistenceService = "doctrine_mongodb";
        } else {
            throw new \UnexpectedValueException("Unknown setting for db_driver");
        }
        $container->setParameter(
            'ac_login_convenience.db_persistence_service',
            $persistenceService
        );

        $userClass = $config["user_model_class"];
        if (is_null($userClass)) {
            if ($config["db_driver"] == "orm") {
                $userClass = "AC\LoginConvenienceBundle\Entity\User";
            } elseif ($config["db_driver"] == "mongodb") {
                $userClass = "AC\LoginConvenienceBundle\Document\User";
            } else {
                throw new \UnexpectedValueException(
                    "Cannot guess user_model_class from db_driver"
                );
            }
        }
        $container->setParameter(
            'ac_login_convenience.user_model_class',
            $userClass
        );

        $identityClass = null;
        if ($config["db_driver"] == "orm") {
            $identityClass = "AC\LoginConvenienceBundle\Entity\OpenIdIdentity";
        } elseif ($config["db_driver"] == "mongodb") {
            $identityClass = "AC\LoginConvenienceBundle\Document\OpenIdIdentity";
        } else {
            throw new \UnexpectedValueException(
                "Cannot guess openid_identity_class from db_driver"
            );
        }
        $container->setParameter(
            'ac_login_convenience.openid_identity_class',
            $identityClass
        );

    }

    private function generateSecurityConf($securedPaths, $dummyMode, $apiKeys)
    {
        # Common settings for Symfony security
        $securityConf = [
            "providers" => [
                "app_users" => [],
                "openid_user_manager" => [
                    "id" => "ac_login_convenience.openid_user_manager"
                ]
            ],
            "firewalls" => [
                "main" => [
                    "pattern" => "^/",
                    "anonymous" => true,
                    "logout" => [
                        "path" => "/openid/logout",
                        "success_handler" => "ac_login_convenience.logout_result_handler"
                    ]
                ]
            ],
            "access_control" => [
                [
                    "path" => "^/openid/.+",
                    "roles" => "IS_AUTHENTICATED_ANONYMOUSLY"
                ]
            ]
        ];

        if (!is_null($apiKeys)) {
            $securityConf['firewalls']['main']['simple_preauth'] = [
                'authenticator' => 'ac_login_convenience.api_key_authenticator',
                'provider' => 'openid_user_manager'
            ];
        }

        if ($dummyMode) {
            # Use AC\LoginConvenienceBundle\DummySecurityFactory
            $securityConf['firewalls']['main']['dummy'] = [
                "login_path" => "/openid/login_openid",
                "check_path" => "/openid/dummy_check",
                "provider" => "app_users",
                "success_handler" => "ac_login_convenience.success_handler",
                "failure_handler" => "ac_login_convenience.failure_handler"
            ];
        } else {
            # Use OpenIdAuthenticationListener from FpOpenIdBundle
            $securityConf['firewalls']['main']['fp_openid'] = [
                "create_user_if_not_exists" => true,
                "login_path" => "/openid/login_openid",
                "check_path" => "/openid/login_check_openid",
                "provider" => "openid_user_manager",
                "required_attributes" => [ "contact/email" ],
                "success_handler" => "ac_login_convenience.success_handler",
                "failure_handler" => "ac_login_convenience.failure_handler"
            ];
        }

        # Require authentication for secure paths
        foreach ($securedPaths as $path) {
            $securityConf["access_control"][] = [
                "path" => "^$path/.+",
                "roles" => "IS_AUTHENTICATED_FULLY"
            ];
        }

        return $securityConf;
    }
}
