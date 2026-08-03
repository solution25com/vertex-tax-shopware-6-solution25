[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25com/vertex-tax-shopware-6-solution25/blob/main/LICENSE)

# Vertex Tax Integration for Shopware 6

## Introduction

The **Vertex Tax Integration Plugin** connects your Shopware 6 store to the **Vertex O Series REST API**, enabling real-time, automated tax calculation at checkout. Vertex is a leading tax compliance engine trusted by businesses worldwide to handle complex tax rules across jurisdictions.

This plugin supports the full order tax lifecycle — from real-time calculation during checkout to transaction commits on order placement and reversals on cancellation or refund. It also provides address validation, B2B tax exemption handling, automatic fallback tax coverage if Vertex is unavailable, and a comprehensive logging system for full visibility and compliance confidence.

---

## Key Features

### Real-Time Tax Calculation
- Calculates accurate taxes during checkout using the **Vertex O Series REST API v2**.
- Automatically allocates cart-level promotions and discounts to individual line items before tax is calculated, so discounted totals are always taxed correctly.

### Automatic Fallback Tax Rate
- If Vertex is unavailable, times out, fails authentication, or returns an unexpected response, the plugin automatically applies a store-configured **fallback tax rate** instead of leaving checkout unable to complete.
- A **test mode** lets you simulate a Vertex outage on demand to verify fallback behavior without disrupting the live connection.

### OAuth 2.0 Authentication
- Secure API authentication with **token caching** to minimize redundant auth requests.

### Full Order Lifecycle Management
- Automatically **commits** transactions on order placement, payment, or shipment (configurable).
- Automatically **reverses** transactions on order cancellation or refund.

### Address Validation
- Validates customer addresses against Vertex's address database for accurate jurisdiction-based tax calculation.

### Product & Shipping Tax Code Mapping
- Map product-level **tax codes** via custom fields and configure a dedicated **shipping tax code**.

### B2B Support
- Handles **VAT ID** verification and **tax exemption** logic for business customers.

### Multi-Environment Support
- Switch between **Production** and **Sandbox** Vertex environments via configuration — no code changes required.

### Debug Mode
- Enable **debug logging** to troubleshoot API communication and tax calculation issues.

### API Connection Testing
- Built-in endpoint to **test your Vertex API connection** directly from the Shopware Admin.

### Comprehensive Logging & Cleanup
- Full logging of API requests, responses, and tax events for audit and troubleshooting purposes.
- Repeated identical tax calculations during checkout are automatically deduplicated in the log, rather than creating a new row every time.
- A dedicated admin screen lets you preview and delete old tax log entries by age (30 days, 3 months, 6 months, custom range, or all), gated behind its own permission.

### Order Administration Visibility
- Vertex tax status is surfaced directly on the order detail and order creation screens in the Shopware Administration, so staff can see at a glance whether Vertex calculated the tax.

---

## Compatibility
- ✅ Shopware 6.7+
- ✅ PHP 8.1+

---

## Get Started

### Installation & Activation

#### GitHub

1. Clone the plugin into your Shopware plugins directory:

```bash
git clone https://github.com/solution25com/vertex-tax-shopware-6-solution25.git custom/plugins/VertexTax
```

2. **Install the Plugin in Shopware 6**

   - Log in to your Shopware 6 Administration panel.
   - Navigate to **Extensions > My Extensions**.
   - Locate the plugin and click **Install**.

3. **Activate the Plugin**

   - After installation, click **Activate** to enable the plugin.
   - Run the following commands from your Shopware root:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate VertexTax
bin/console cache:clear
```

4. **Verify Installation**

   - After activation, you will see **Vertex Tax Integration** in the list of installed plugins.
   - The plugin name, version, and installation date should appear.

---

## Plugin Configuration

After installing the plugin, configure your **Vertex** credentials and options through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Settings > Extensions > Vertex Tax Integration**
2. Select the **Sales Channel** you want to configure
3. Set the following fields:

### General Settings

| Field | Description |
|---|---|
| **Active** | Enable or disable the Vertex Tax Integration for the selected Sales Channel |
| **Debug Mode** | Enable detailed API logging for troubleshooting |
| **Environment** | Select `Production` or `Sandbox` |

### API Credentials

| Field | Description |
|---|---|
| **Client ID** | Your Vertex OAuth 2.0 client ID |
| **Client Secret** | Your Vertex OAuth 2.0 client secret |
| **Username** | Your Vertex account username |
| **Password** | Your Vertex account password |
| **Company Code** | Your Vertex company code (default: `DEFAULT`) |

### Tax Code Settings

| Field | Description |
|---|---|
| **Default Product Tax Code** | Fallback tax code applied to products without a specific tax code assigned (default: `DEFAULT`) |
| **Shipping Tax Code** | Tax code applied to shipping charges (default: `FREIGHT`) |

### Transaction Settings

| Field | Description |
|---|---|
| **Commit Tax Flow** | When to commit the transaction to Vertex: `Immediate (on order placed)`, `When order is paid`, or `When order is shipped` |
| **Transaction ID Prefix** | Prefix added to Vertex transaction IDs for identification (default: `SW`) |

### Fallback Settings

| Field | Description |
|---|---|
| **Fallback tax rate (%) if Vertex is unavailable or fails** | Flat tax rate applied automatically to the cart and order when Vertex cannot be reached or returns an invalid response. Accepts 0–100. Default: `0` |
| **Test: force Vertex tax failure (skips live API, uses fallback)** | When enabled, skips the live Vertex call entirely and always applies the fallback tax rate — useful for verifying fallback behavior in a staging environment |

### Origin Address

The origin address is used by Vertex to determine the correct tax jurisdiction for shipments.

| Field | Description |
|---|---|
| **Origin Street Address 1** | Primary street address of your business origin |
| **Origin Street Address 2** | Secondary street address (optional) |
| **Origin City** | City of your business origin |
| **Origin State Code** | State code (e.g. `CA` for California) |
| **Origin Postal Code** | ZIP / postal code of your business origin |
| **Origin Country Code** | Country code of your business origin (default: `USA`) |

---

## How It Works

### 1. Tax Calculation at Checkout

When a customer proceeds through checkout, the plugin sends the cart contents and customer address to the **Vertex O Series REST API** in real time. Vertex returns the applicable tax amounts per line item, which are applied to the Shopware cart before the customer confirms the order.

### 2. Fallback Tax Calculation

If Vertex is unreachable, times out, fails authentication, or returns an invalid response, the plugin automatically applies the store-configured **fallback tax rate** to the cart's net line items and shipping instead of leaving checkout blocked. Line items and orders calculated this way are flagged internally so they can be distinguished from Vertex-calculated results in the logs.

### 3. Address Validation

The plugin validates the customer's shipping address against Vertex's address service to ensure the correct tax jurisdiction is applied.

### 4. Transaction Commit

Once an order is placed, paid, or shipped (depending on your **Commit Tax Flow** setting), the plugin commits the transaction to Vertex — creating a permanent tax record for compliance and reporting purposes.

### 5. Transaction Reversal

If an order is cancelled or refunded, the plugin automatically sends a **reversal transaction** to Vertex to keep your tax records accurate and up to date.

### 6. Product Tax Code Mapping

Individual products can be assigned a **Vertex tax code** via Shopware custom fields, enabling precise tax treatment per product category or type. If no code is set on a product, the **Default Product Tax Code** from configuration is used as a fallback.

### 7. Tax Log Management

Every tax calculation and transaction event is logged for audit purposes, with duplicate near-identical entries collapsed automatically. From **Settings > Vertex Tax Integration > Log**, admins with the appropriate permission can review entries and clean up old logs by age or custom date range.

---

## Uninstallation

```bash
bin/console plugin:deactivate VertexTax
bin/console plugin:uninstall VertexTax
bin/console cache:clear
```
