<?php
/**
 * @author Ahmed El-Araby <araby2305@gmail.com>
 */

namespace Logixie\MageplazaSocialLoginApi\Api;

interface SocialCustomerTokenServiceInterface
{
    /**
     * @param string $provider
     * @param string $token
     * @param string $tokenSecret
     * @param string $refreshToken
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function createCustomerAccessToken($provider, $token, $tokenSecret = '', $refreshToken = '');
}
