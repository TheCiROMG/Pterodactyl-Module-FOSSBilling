# 🦖 Pterodactyl Module for FOSSBilling

A robust, secure, and feature-rich Pterodactyl integration for FOSSBilling. This module allows you to automatically provision, manage, and resell Pterodactyl game servers directly from your FOSSBilling installation with a focus on stability and security.

**Author:** [TheCiROMG](https://github.com/TheCiROMG)  
**Version:** 1.1.0  
**Compatibility:** FOSSBilling 0.6.x+ / Pterodactyl 1.x+

---

## ✨ Key Features

### 🚀 Automated Provisioning
- **Instant Activation**: Automatically creates servers on Pterodactyl immediately upon payment confirmation.
- **Smart Validation**: Checks for available resources (RAM/Disk) before attempting to create a server to prevent errors.

### 🔄 Intelligent Node Selection
- **Location-Based Auto-Selection**: Automatically finds the best node in a selected location that has sufficient free RAM and Disk.
- **Admin-Defined Available Nodes (Global)**: Administrators can define a global pool of allowed nodes in the module settings. The module will automatically pick a node from that pool with enough free RAM, Disk, and (optionally) ports.
- **Exhaustion Handling**: If none of the selected nodes can host the service, the order fails with a clear error message so you know that all nodes for that service/category are full.

### 🔒 Enhanced Security & SSO
- **WemX SSO Support**: Native integration with the [WemX SSO Plugin](https://github.com/WemxPro/sso-pterodactyl) for secure, one-click login from FOSSBilling to Pterodactyl.
- **No Password Storage**: This module intentionally **does not** handle or store Pterodactyl panel passwords to ensure maximum security. Users are directed to the panel to manage their credentials.
- **Client Isolation**: Clients have a streamlined view focused on connection and access, preventing accidental misconfiguration.

### 🛠️ Advanced Configuration
- **Egg Variable Support**: Automatically loads and allows configuration of all variables defined in your Pterodactyl Eggs.
- **Auto-Port Allocation (Multi-Allocation)**: Use `AUTO_PORT` in any variable to automatically assign free ports from the node. The module now requests `1 + N` allocations and includes `allocation.additional` in the creation payload to ensure perfect mapping for multi-port eggs (e.g., FiveM).
- **Auto-Password Generation**: Use `AUTO_PASSWORD` or `RANDOM_STRING` to generate secure credentials for server variables (e.g., RCON).
- **Custom Docker Images**: Override default Egg images per product.
- **Resource Limits**: Granular control over CPU, RAM, Disk, Swap, IO, Databases, Allocations, and Backups.

---

## 📥 Installation

1.  **Download**: Get the latest version of the module.
2.  **Upload**: Place the `Servicepterodactyl` folder into your FOSSBilling `modules` directory (e.g., `/var/www/fossbilling/modules/`).
3.  **Activate**:
    - Log in to your FOSSBilling Admin Panel.
    - Navigate to **Extensions** > **Overview**.
    - Find "Pterodactyl" and click **Activate**.

---

## ⚙️ Configuration

### 1. Global Module Settings
Navigate to **Extensions** > **Pterodactyl** (or the Settings icon).

- **Panel URL**: Full URL to your Pterodactyl Panel (e.g., `https://panel.example.com`).
- **API Key**: An **Application API Key** from Pterodactyl.
  - *Create this in Pterodactyl Admin > Application API.*
  - *Permissions Required*: Users (Read/Write), Servers (Read/Write), Nodes (Read), Allocations (Read/Write).
- **Client API Key (Admin)**: Optional admin client API key used for network operations (assign allocation, set primary, notes) via client endpoints. Not exposed in UI actions by default; kept for advanced scenarios.
- **SSO Secret**: (Optional) The secret key from the WemX SSO plugin.
- **Allowed Nodes**: Select which nodes FOSSBilling is allowed to deploy to.

### 2. Product Setup
When configuring a product in FOSSBilling:

1.  **Type**: Select "Pterodactyl".
2.  **Configuration Tab**:
    - **Deployment**: Choose a specific Node or a Location for auto-selection.
    - **Egg Selection**: Pick the Nest and Egg. Wait for the page to reload to see Egg-specific variables.
    - **Resources**: Set limits for Memory, Disk, CPU, etc.
    - **Feature Limits**: Set allowed Databases, Backups, and Allocations.

### 3. Special Variable Patterns
You can use these placeholders in any Egg Variable field:

- `AUTO_PORT`: Assigns a random free port from the node.
- `AUTO_PORT` now supports multiple occurrences; ports are allocated and mapped in order.
- `AUTO_PASSWORD`: Generates a secure 16-character alphanumeric password.
- `{{ client.first_name }}`: Inserts client's first name.
- `{{ client.id }}`: Inserts client's ID.
- `{{ service.id }}`: Inserts the service ID.

---

## ♻️ Lifecycle Logic

This module follows a strict lifecycle to manage resources efficiently:

| FOSSBilling Action | Pterodactyl Action | Description |
| :--- | :--- | :--- |
| **Activate** | **Create Server** | Creates a new server and user (if needed). |
| **Suspend** (Overdue) | **Suspend Server** | Stops the server and disables access. Files are kept. |
| **Cancel** (Terminated) | **Delete Server** | **Destroys** the server and all files to free up resources on the node. |
| **Unsuspend** | **Unsuspend Server** | Reactivates a suspended server. |
| **Uncancel** | **Re-Provision** | If the server was deleted, a **new** one is created. |

> **Note:** Cancellation (usually after the grace period expires) is destructive. This is by design to prevent unpaid servers from consuming disk space indefinitely.

---

## 🔑 Single Sign-On (SSO) Setup

To enable the "Login to Panel" button for your clients:

1.  Install the **WemX SSO Plugin** on your Pterodactyl instance: [Installation Guide](https://github.com/WemxPro/sso-pterodactyl).
2.  Generate an `SSO_SECRET` in the plugin configuration.
3.  Copy this secret into the **SSO Secret** field in the FOSSBilling Pterodactyl module settings.
4.  The "Login (SSO)" button will automatically appear for clients.

---

## 🤝 Contributing

Bug reports and pull requests are welcome on GitHub.

**License:** Apache-2.0  
**Repository:** [GitHub](https://github.com/TheCiROMG/Pterodactyl-Module-FOSSBilling)

---

## 📦 Changelog

### 1.1.0
- Global Allowed Nodes configuration (moved from product to module settings).
- Multi-allocation support for `AUTO_PORT` with `allocation.additional` in server creation.
- Client API Key field added (for admin client network endpoints; not exposed by default).
- FQDN support and validation for allocation host resolution.
- Idempotent server creation using `external_id` to prevent duplicates on retries.
- Security: Admin settings mask API keys in responses and avoid overwriting on blank values.

---

## ⚡ AUTO_PORT Quick Guide

### Requirements
- Properly configured Panel URL (HTTPS recommended).
- Application API Key with permissions: Users (RW), Servers (RW), Nodes (R), Allocations (RW).
- Admin Client API Key for your Pterodactyl admin user:
  - Enter this value in “Client API Key” within the module settings.
  - Used for client-side network operations (assign, set primary, notes) if required by your panel.

### Node Declaration (IP or Domain)
- In module settings, under node configuration, specify for each Node ID:
  - host: public IP of the node or FQDN (domain) that resolves to that IP.
  - port_start and port_end: port range available for AUTO_PORT.
- Notes:
  - If using FQDN, ensure it resolves via DNS to the node’s IP.
  - Do not mix IP and FQDN pointing to different nodes.
  - Allowed Nodes must include the Node ID used by the product.

### Product Configuration
- In Egg variables, put AUTO_PORT wherever automatic ports are needed.
- Each AUTO_PORT occurrence counts as 1 port to assign.
- The module computes N occurrences and requests 1 + N allocations:
  - “default” for the primary port.
  - “additional” for extra ports.

### What AUTO_PORT Does
- Detects how many ports are required based on Egg variables.
- Reserves free ports on the configured node (IP/FQDN + range).
- Creates the server including allocation.default and allocation.additional in the payload so ports are linked from the start.
- Sets feature_limits.allocations = 1 + N to allow those assignments on the panel.
- With Client API Key, performs client-side network actions (set primary, notes) if your panel requires it.

### Notes and Best Practices
- Ensure enough free ports exist within the configured range.
- If FQDN does not resolve, use a direct IP.
- Keep Allowed Nodes and Default Node consistent with your products.
- Avoid extra panel restrictions that block “additional allocations”.
