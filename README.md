# 🦖 Pterodactyl Module for FOSSBilling

A robust, secure, and feature-rich Pterodactyl integration for FOSSBilling. This module allows you to automatically provision, manage, and resell Pterodactyl game servers directly from your FOSSBilling installation with a focus on stability and security.

**Author:** [TheCiROMG](https://github.com/TheCiROMG)  
**Version:** 1.0.2  
**Compatibility:** FOSSBilling 0.6.x+ / Pterodactyl 1.x+

---

## ✨ Key Features

### 🚀 Automated Provisioning
- **Instant Activation**: Automatically creates servers on Pterodactyl immediately upon payment confirmation.
- **Smart Validation**: Checks for available resources (RAM/Disk) before attempting to create a server to prevent errors.

### 🔄 Intelligent Node Selection
- **Location-Based Auto-Selection**: Automatically finds the best node in a selected location that has sufficient free RAM and Disk.
- **Specific Node Assignment**: Option to force deployment to a specific node.
- **Global Default**: Fallback to a default node if no specific location is requested.

### 🔒 Enhanced Security & SSO
- **WemX SSO Support**: Native integration with the [WemX SSO Plugin](https://github.com/WemxPro/sso-pterodactyl) for secure, one-click login from FOSSBilling to Pterodactyl.
- **No Password Storage**: This module intentionally **does not** handle or store Pterodactyl panel passwords to ensure maximum security. Users are directed to the panel to manage their credentials.
- **Client Isolation**: Clients have a streamlined view focused on connection and access, preventing accidental misconfiguration.

### 🛠️ Advanced Configuration
- **Egg Variable Support**: Automatically loads and allows configuration of all variables defined in your Pterodactyl Eggs.
- **Auto-Port Allocation**: Use `AUTO_PORT` in any variable to automatically assign a free port from the node.
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
  - *Permissions Required*: Users (Read/Write), Servers (Read/Write), Nodes (Read), Allocations (Read).
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
