<?php
/**
 * @author Ahmed El-Araby <araby2305@gmail.com>
 */

namespace Logixie\MageplazaSocialLoginApi\Model;

use Logixie\MageplazaSocialLoginApi\Api\SocialCustomerTokenServiceInterface;
use Magento\Integration\Model\Oauth\Token\RequestThrottler;

/**
 * Class SocialCustomerTokenService
 * @package Logixie\MageplazaSocialLoginApi\Model
 * @author Ahmed El-Araby <araby2305@gmail.com>
 */
class SocialCustomerTokenService implements SocialCustomerTokenServiceInterface
{
    /**
     * @var \Mageplaza\SocialLogin\Model\Social
     */
    private $socialModel;

    /**
     * @var \Mageplaza\SocialLogin\Helper\Social
     */
    private $socialHelper;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var \Magento\Integration\Model\Oauth\Token\RequestThrottler
     */
    private $requestThrottler;

    /**
     * @var \Magento\Integration\Model\Oauth\TokenFactory
     */
    private $tokenModelFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * SocialCustomerTokenService constructor.
     * @param \Mageplaza\SocialLogin\Model\SocialFactory $socialFactory
     * @param \Mageplaza\SocialLogin\Helper\Social $socialHelper
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param RequestThrottler $requestThrottler
     * @param \Magento\Integration\Model\Oauth\TokenFactory $tokenModelFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Mageplaza\SocialLogin\Model\SocialFactory $socialFactory,
        \Mageplaza\SocialLogin\Helper\Social $socialHelper,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Integration\Model\Oauth\Token\RequestThrottler $requestThrottler,
        \Magento\Integration\Model\Oauth\TokenFactory $tokenModelFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->socialModel = $socialFactory->create();
        $this->socialHelper = $socialHelper;
        $this->eventManager = $eventManager;
        $this->requestThrottler = $requestThrottler;
        $this->tokenModelFactory = $tokenModelFactory;
        $this->storeManager = $storeManager;
    }


    /**
     * @param string $provider
     * @param string $token
     * @param string $tokenSecret
     * @param string $refreshToken
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createCustomerAccessToken($provider, $token, $tokenSecret = '', $refreshToken = '')
    {
        $type = $this->socialHelper->setType($provider);

        if (!$type) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The provider "%1" is invalid, available types are "%2"', $provider, implode(', ', array_keys($this->socialHelper->getSocialTypes())))
            );
        }

        $authParams = [
            'providers' => [
                $type => $this->socialModel->getProviderData($type)
            ],
            'debug_mode' => false,
            'debug_file' => BP . '/var/log/social.log'
        ];

        \Hybrid_Auth::initialize($authParams);

        \Hybrid_Auth::storage()->set("hauth_session.$type.token.access_token", $token);
        \Hybrid_Auth::storage()->set("hauth_session.$type.token.access_token_secret", $tokenSecret);
        \Hybrid_Auth::storage()->set("hauth_session.$type.token.refresh_token", $refreshToken);

        $socialAdapterProvider = \Hybrid_Auth::setup($type, $authParams);

        $socialAdapterProvider->adapter->token('access_token', $token);
        $socialAdapterProvider->adapter->token('access_token_secret', $tokenSecret);
        $socialAdapterProvider->adapter->token('refresh_token', $refreshToken);

        $userProfile = $socialAdapterProvider->adapter->getUserProfile();

        $customer = $this->socialModel->getCustomerBySocial($userProfile->identifier, $type);

        if (!$customer->getId()) {
            $customer = $this->createCustomerProcess($userProfile, $type);
        }

        $this->eventManager->dispatch('customer_login', ['customer' => $customer->getDataModel()]);
        $this->requestThrottler->resetAuthenticationFailuresCount($customer->getEmail(), RequestThrottler::USER_TYPE_CUSTOMER);
        return $this->tokenModelFactory->create()->createCustomerToken($customer->getId())->getToken();
    }

    /**
     * @param $userProfile
     * @param $type
     * @return \Magento\Customer\Model\Customer|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createCustomerProcess($userProfile, $type)
    {
        $name = explode(' ', $userProfile->displayName ?: __('New User'));

        $user = array_merge(
            [
                'email' => $userProfile->email ?: $userProfile->identifier . '@' . strtolower($type) . '.com',
                'firstname' => $userProfile->firstName ?: (array_shift($name) ?: $userProfile->identifier),
                'lastname' => $userProfile->lastName ?: (array_shift($name) ?: $userProfile->identifier),
                'identifier' => $userProfile->identifier,
                'type' => $type,
                'password' => isset($userProfile->password) ? $userProfile->password : null
            ],
            $this->getUserData($userProfile)
        );

        $customer = $this->socialModel->getCustomerByEmail($user['email'], $this->storeManager->getStore()->getWebsiteId());
        if ($customer->getId()) {
            $this->socialModel->setAuthorCustomer($user['identifier'], $customer->getId(), $type);
        } else {
            $customer = $this->socialModel->createCustomerSocial($user, $this->storeManager->getStore());
        }

        return $customer;
    }

    /**
     * @param $profile
     *
     * @return array
     */
    public function getUserData($profile)
    {
        return [];
    }
}
