# Changelog
All notable changes to the Vertex Tax Integration plugin will be documented in this file.

## [1.1.1] - 2026-09-15

### Added
- Dedicated logging for the Vertex Tax plugin: logs are now written to a separate rotating log file instead of Shopware's general `dev.log`, making it easier to locate and troubleshoot Vertex-specific issues. Old log files are automatically cleaned up after 14 days.

## [1.1.0] - 2026-08-03

### This release includes

- Fixed the live tax calculation and API request flow to use the actual cart or order data.
- Added a configurable fallback tax calculation that applies a store-defined flat tax rate automatically if the Vertex API is unreachable, times out, fails authentication, or returns an unexpected response, so checkout is never blocked by an API outage.
- Added a test-only setting to simulate a Vertex API failure, making it possible to verify fallback behavior without needing to break the live connection.
- Added automatic transaction commit when an order is marked as paid or as shipped, in addition to the existing commit-on-order-placed option, giving merchants more control over when tax is finalized with Vertex.
- Improved deduplication of tax log entries so repeated identical tax calculations during checkout no longer create duplicate log rows; matching entries are now merged with an occurrence count and last-seen timestamp.
- Improved handling of promotional discounts so they are now correctly allocated per line item before tax is calculated and sent to Vertex.
- Improved cleanup of zero-rate tax rows left on the cart or order in fallback or partial-calculation scenarios.
- Improved the invoice commit and reversal flow to use the transaction and document identifiers returned by Vertex, and to call the dedicated reversal endpoint for refunds and cancellations.
- Added an administration screen action to preview and delete old tax log entries by date range, with a dedicated permission for this action.
- Added tax status visibility directly on the order detail and order creation screens in the administration panel.
- Added a database index and additional fields to support faster and more reliable tax log lookups.

---

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

