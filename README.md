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
- **Multi-Location Support**: Allow clients to choose their preferred server location during checkout.
- **Smart Capacity Check**: Automatically disables locations that are full (no nodes with sufficient RAM/Disk) in the checkout form.
- **Admin-Defined Available Nodes (Global)**: Administrators can define a global pool of allowed nodes.

### 🎨 Flexible Configuration
- **Plans System**: Define custom plans (e.g., "Basic", "Pro", "Ultra") with different resource limits and prices, all within a single FOSSBilling product.
- **User-Configurable Variables**: Allow clients to configure specific Egg variables (e.g., Server Name, RCON Password) during checkout.
- **Per-Product Customization**: All settings (plans, locations, variables) are configured per product, giving you full control.

### 🔒 Enhanced Security & SSO
- **WemX SSO Support**: Native integration with the [WemX SSO Plugin](https://github.com/WemxPro/sso-pterodactyl) for secure, one-click login from FOSSBilling to Pterodactyl.
- **Secure Handling**: Sensitive data is filtered and not exposed to the client.
- **Input Validation**: Rigorous validation of user inputs to prevent injection attacks.

### 🛠️ Advanced Configuration
- **Egg Variable Support**: Automatically loads and allows configuration of all variables defined in your Pterodactyl Eggs.
- **Auto-Port Allocation**: Use `AUTO_PORT` in any variable to automatically assign free ports.
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
- **Allowed Nodes**: Select which nodes FOSSBilling is allowed to deploy to.

### 2. Product Setup
When configuring a product in FOSSBilling:

1.  **Type**: Select "Pterodactyl".
2.  **Configuration Tab**:
    - **Deployment**: 
      - **Specific Node**: Force deployment to a specific node.
      - **Location (Auto-Selection)**: Select multiple locations to allow the user to choose. The system will auto-select a node in that location.
    - **Egg Selection**: Pick the Nest and Egg.
    - **Egg Variables**: Configure default values and toggle "Allow user to configure" for variables you want clients to set.
    - **Plans**: Use the "Plans JSON" field to define multiple tiers (see example in the field).
    - **Resources**: Set default limits.

### 3. Special Variable Patterns
You can use these placeholders in any Egg Variable field:

- `AUTO_PORT`: Assigns a random free port from the node.
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
