# 🦖 Pterodactyl Module for FOSSBilling

A complete, feature-rich Pterodactyl integration for FOSSBilling. This module allows you to automatically provision, manage, and resell Pterodactyl game servers directly from your FOSSBilling installation.

**Author:** [TheCiROMG](https://github.com/TheCiROMG)  
**Version:** 1.0.1

---

## ✨ Features

- **🚀 Auto-Provisioning**: Automatically create servers on Pterodactyl upon payment.
- **🔄 Smart Node Selection**: 
  - Assign specific nodes per product.
  - **Auto-Selection**: Automatically pick the best node in a location with available stock (RAM/Disk checks).
- **🔒 SSO Integration**: One-click login to Pterodactyl panel from FOSSBilling client area (supports WemX SSO plugin).
- **🛠️ Advanced Configuration**:
  - **Egg Variables**: Intelligent loading of Egg variables based on selected Egg.
  - **Docker Images**: Configure custom Docker images.
  - **Startup Commands**: Customize startup commands.
  - **CPU Pinning & OOM Killer**: Toggle advanced Pterodactyl features.
- **🔌 Auto-Port Allocation**: Automatically assign free ports to server variables using the `AUTO_PORT` placeholder.
- **🔑 Auto-Password Generation**: Automatically generate secure random passwords for variables using `AUTO_PASSWORD`.
- **🏷️ Custom Server Naming**: Define server naming patterns using client variables (e.g., `Server - {{ client.first_name }}`).
- **📊 Resource Management**: Set limits for CPU, RAM, Disk, Swap, IO, Databases, Backups, and Allocations.
- **🌐 Internationalization**: Native support for multiple languages (English, Spanish, Portuguese).

## 📥 Installation

1.  **Download**: Download the latest release of this module.
2.  **Upload**: Upload the `Servicepterodactyl` folder to your FOSSBilling `modules` directory (usually `/path/to/fossbilling/modules/`).
3.  **Activate**:
    - Log in to your FOSSBilling Admin Panel.
    - Go to **Extensions** > **Overview**.
    - Find "Pterodactyl" in the list and click **Activate**.

## ⚙️ Configuration

### 1. Global Module Settings
Go to **Extensions** > **Pterodactyl** (or click the "Settings" icon next to the module).

- **Panel URL**: Your Pterodactyl Panel URL (e.g., `https://panel.yourdomain.com`).
- **API Key**: Your Pterodactyl Application API Key.
  - *Note*: Create this in Pterodactyl Admin > Application API. Read/Write permissions are recommended for full functionality.
- **SSO Secret**: (Optional) The secret key from your WemX SSO plugin configuration to enable one-click login.
- **Allowed Nodes**: Select which nodes from your Pterodactyl panel are allowed to be used by FOSSBilling.

### 2. Product Configuration
When creating or editing a product in FOSSBilling:

1.  Set **Type** to "Pterodactyl".
2.  Go to the **Configuration** tab.
3.  **Deployment Settings**:
    - Choose **Specific Node** or **Location (Auto-Selection)**.
4.  **Egg Settings**: 
    - Select the **Nest** and **Egg**. 
    - The module will automatically load the Egg's environment variables.
5.  **Resources & Limits**: 
    - Configure RAM, Disk, CPU, Swap, and IO.
    - **Feature Limits**: Set dedicated limits for **Databases**, **Allocations**, and **Backups**.

### 🪄 Special Features

#### 🔌 Auto-Port Allocation
You can automatically assign a free port to any server variable (like `SERVER_PORT` or `QUERY_PORT`).
- Set the variable value to: `AUTO_PORT`
- The module will find a free port on the node and replace it during installation.

#### 🔑 Auto-Password Generation
To generate a random secure password for variables like `RCON_PASSWORD` or `ADMIN_PASSWORD`:
- Set the variable value to: `AUTO_PASSWORD` or `RANDOM_STRING`
- The module will generate a 16-character secure string.

#### 🏷️ Server Name Pattern
You can customize how servers are named on Pterodactyl.
- **Field**: Server Name Pattern
- **Variables**:
  - `{{ client.id }}`
  - `{{ client.first_name }}`
  - `{{ client.last_name }}`
  - `{{ service.id }}`
  - `{{ date }}`
- **Example**: `{{ product.title }} - {{ client.first_name }}`

## 👤 Client Experience

The client area has been streamlined to focus on access rather than management, encouraging users to use the Pterodactyl Panel for full control.

- **Simplified Dashboard**: Shows connection details (IP/Port) and server status.
- **Direct Access**: 
  - **Access Panel**: Link to the Pterodactyl server page.
  - **Login (SSO)**: One-click login (if configured).
- **No Variables Exposed**: Clients cannot see or edit server variables from FOSSBilling; this is handled entirely by admins or auto-generated.

## 🔑 SSO Setup (Optional)

To enable the "Login to Panel" button in the client area:

1.  Install the [WemX SSO Plugin](https://github.com/WemxPro/sso-pterodactyl) on your Pterodactyl panel.
2.  Copy the `SSO_SECRET` from your Pterodactyl configuration.
3.  Paste it into the **SSO Secret** field in the FOSSBilling Pterodactyl module settings.

## 🤝 Support & Contributing

This module is a complete rework aimed at stability and feature parity with modern hosting needs.

- **Issues**: Please report bugs on the [GitHub Repository](https://github.com/TheCiROMG/Pterodactyl-Module-FOSSBilling).
- **Contributions**: Pull requests are welcome!

---

Made with ❤️ by **TheCiROMG**
