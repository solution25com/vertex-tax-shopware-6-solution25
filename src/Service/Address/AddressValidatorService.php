<?php

declare(strict_types=1);

namespace VertexTax\Service\Address;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;

class AddressValidatorService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Validate address for Vertex tax calculation
     *
     * @param CustomerAddressEntity $address
     * @return array{valid: bool, errors: array}
     */
    public function validateAddress(CustomerAddressEntity $address): array
    {
        $errors = [];

        if (empty($address->getStreet())) {
            $errors[] = 'Street address is required';
        }

        if (empty($address->getCity())) {
            $errors[] = 'City is required';
        }

        if (empty($address->getZipcode())) {
            $errors[] = 'Postal code is required';
        }

        $country = $address->getCountry();
        if (!$country) {
            $errors[] = 'Country is required';
        } else {
            if ($this->isUSAddress($country)) {
                $state = $address->getCountryState();
                if (!$state) {
                    $errors[] = 'State is required for US addresses';
                } else {
                    $stateCode = $this->normalizeStateCode($state->getShortCode());
                    if (empty($stateCode) || strlen($stateCode) !== 2) {
                        $this->logger->warning('Invalid state code for US address', ['stateCode' => $stateCode]);
                        $errors[] = 'Valid state code is required (2-letter abbreviation)';
                    }
                }
            }
        }

        if ($country && $this->isUSAddress($country) && !empty($address->getZipcode())) {
            $zipcode = $address->getZipcode();
            if (!preg_match('/^\d{5}(-\d{4})?$/', $zipcode)) {
                $errors[] = 'Invalid US postal code format (expected: 12345 or 12345-6789)';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Normalize state code
     *
     * @param string $stateCode
     * @return string
     */
    public function normalizeStateCode(string $stateCode): string
    {
        if (strpos($stateCode, '-') !== false) {
            $parts = explode('-', $stateCode);
            return strtoupper(end($parts));
        }

        return strtoupper(trim($stateCode));
    }

    /**
     * Normalize country code to ISO 3166-1 alpha-3
     *
     * @param string $countryCode
     * @return string
     */
    public function normalizeCountryCode(string $countryCode): string
    {
        $countryMap = [
            'US' => 'USA',
            'CA' => 'CAN',
            'MX' => 'MEX',
        ];

        $upperCode = strtoupper($countryCode);
        return $countryMap[$upperCode] ?? $upperCode;
    }

    /**
     * Check if address is US address
     *
     * @param CountryEntity $country
     * @return bool
     */
    private function isUSAddress(CountryEntity $country): bool
    {
        return strtoupper($country->getIso() ?? '') === 'US';
    }

    /**
     * Format address for Vertex API
     *
     * @param CustomerAddressEntity $address
     * @return array
     */
    public function formatAddressForVertex(CustomerAddressEntity $address): array
    {
        $country = $address->getCountry();
        $state = $address->getCountryState();

        $formatted = [
            'streetAddress1' => $address->getStreet(),
            'city' => $address->getCity(),
            'postalCode' => $address->getZipcode() ?? '',
            'country' => $this->normalizeCountryCode($country?->getIso() ?? 'US'),
        ];

        if ($state) {
            $formatted['mainDivision'] = $this->normalizeStateCode($state->getShortCode());
        } elseif ($formatted['country'] === 'USA') {
            $formatted['mainDivision'] = '';
        }

        return $formatted;
    }
}
