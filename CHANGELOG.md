# Changelog

All notable changes to the Vertex Tax Integration plugin will be documented in this file.

## [1.0.0] - 2025-01-XX

### Added
- Initial release of Vertex Tax Integration for Shopware 6.7
- OAuth 2.0 authentication with token caching
- Real-time tax calculation using Vertex O Series REST API v2
- Address validation service
- Order lifecycle management (commit/reversal)
- Comprehensive logging system
- Multi-environment support (Production/Sandbox)
- Configuration interface
- Transaction builder for cart and order conversion
- Tax calculator with fallback support
- Cart processor integration
- Order event subscribers

### Features
- Automated tax calculation during checkout
- Transaction commit on order placement/paid/shipped (configurable)
- Transaction reversal on order cancellation/refund
- Product tax code mapping via custom fields
- Shipping tax code configuration
- B2B support with VAT ID and tax exemption handling
- Debug mode for troubleshooting
- API connection testing endpoint

### Technical Details
- PHP 8.1+ compatible
- Shopware 6.7+ compatible
- Uses Guzzle HTTP client for API communication
- Implements Shopware tax provider pattern
- Follows Shopware coding standards and best practices

