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
- **Idempotent Provisioning**: Uses a stable `external_id` so retries won’t create duplicates (existing servers are reused when possible).

### 🔄 Intelligent Node Selection
- **Location-Based Auto-Selection**: Automatically finds the best node in a selected location that has sufficient free RAM and Disk.
- **Multi-Location Support**: Allow clients to choose their preferred server location during checkout.
- **Smart Capacity Check**: Automatically disables locations that are full (no nodes with sufficient RAM/Disk) in the checkout form.
- **Admin-Defined Available Nodes (Global)**: Administrators can define a global pool of allowed nodes.

### 🎨 Flexible Configuration
- **Plans System**: Define custom plans (e.g., "Basic", "Pro", "Ultra") with different resource limits and prices, all within a single FOSSBilling product.
- **User-Configurable Variables**: Allow clients to configure specific Egg variables (e.g., Server Name, RCON Password) during checkout.
- **Per-Product Customization**: All settings (plans, locations, variables) are configured per product, giving you full control.
- **Server Naming Pattern**: Use templates like `{{ product.title }} - {{ client.first_name }}` to auto-generate names.

### 🔒 Enhanced Security & SSO
- **WemX SSO Support**: Native integration with the [WemX SSO Plugin](https://github.com/WemxPro/sso-pterodactyl) for secure, one-click login from FOSSBilling to Pterodactyl.
- **Secure Handling**: Sensitive data is filtered and not exposed to the client.
- **Input Validation**: Rigorous validation of user inputs to prevent injection attacks.

### 🛠️ Advanced Configuration
- **Egg Variable Support**: Automatically loads and allows configuration of all variables defined in your Pterodactyl Eggs.
- **Auto-Port Allocation**: Use `AUTO_PORT` in any variable to automatically assign free ports.
- **Auto Password**: Use `AUTO_PASSWORD` (or `RANDOM_STRING` / `GENERATE_RANDOM`) in any variable to generate a secure random value.
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
- **API Key**: An **Application API Key** from Pterodactyl (usually starts with `ptla_`).
- **Client API Key**: An **Admin Client API Key** (usually starts with `ptlc_`). Optional unless you use client-only actions (e.g., setting “primary allocation” via client context).
- **SSO Secret**: Secret key for the WemX SSO plugin (only needed if you use the “Login to Panel”/SSO feature).
- **Allowed Nodes**: Select which nodes this module can deploy to (filters node lists in product config).
- **Default Node**: Fallback node when no node/location is selected at product level.

### 2. Node Configuration (AUTO_PORT / Port Ranges)
In the **Node Configuration** tab you can define a per-node allocation policy:

- **IP or FQDN**: The address that should match an Address configured on the Node in Pterodactyl.
- **Port Start / Port End**: Optional range to constrain port allocation/creation used by `AUTO_PORT`.

This is useful when:
- You want ports allocated only from a specific range per node.
- Nodes have multiple IPs and you want allocations created on a specific IP/address.

If this is left empty, the module will still allocate ports, but it will not prefer a specific host or range.

Notes:
- The **host/IP** here is for **allocations** (game ports), not for Wings/daemon. It does not auto-detect custom Wings ports.
- If multiple Egg variables are set to `AUTO_PORT`, the module can allocate multiple ports at creation time (one default + additional allocations).

### 3. Debug Tools
In the **Debug** tab you can run a quick connectivity test and see node capacity data (RAM/Disk/Maintenance). This is the fastest way to confirm your Panel URL and Application API key are correct.

### 4. Product Setup
When configuring a product in FOSSBilling:

1.  **Type**: Select "Pterodactyl".
2.  **Configuration Tab**:
    - **Deployment**: 
      - **Specific Node**: Force deployment to a specific node.
      - **Location (Auto-Selection)**: Select multiple locations to allow the user to choose during checkout. The system will auto-select a node in that location with enough free resources.
    - **Egg Selection**: Pick the Nest and Egg.
    - **Egg Variables**: Configure default values and toggle "Allow user to configure" for variables you want clients to set.
    - **Plans**: Use the "Plans JSON" field to define multiple tiers (see example in the field).
    - **Resources**: Set default limits.
    - **Docker & Startup**: Optionally override the Egg’s default docker image and startup command.
    - **Feature Limits**:
      - **Allocations / Backups / Databases**: Sets the feature limits on the server in Pterodactyl.
      - **CPU Pinning (Threads)**: Sets `limits.threads` (advanced; uses the panel format like `0`, `0-1`, `3`).
      - **OOM Disabled**: Toggles `oom_disabled` for the server.
      - **Server Name Pattern**: Template used to generate the server name at provisioning time.
      - **Server Description**: Optional description field stored in the panel.
    - **Dedicated IP**: Present in the product config UI, but currently not used by the provisioning logic (informational/legacy toggle).

### 5. Special Variable Patterns
You can use these placeholders in any Egg Variable field:

- `AUTO_PORT`: Assigns a random free port from the node.
- `AUTO_PASSWORD` / `RANDOM_STRING` / `GENERATE_RANDOM`: Generates a random secure string.
- `{{ client.first_name }}`: Inserts client's first name.
- `{{ client.last_name }}`: Inserts client's last name.
- `{{ client.id }}`: Inserts client's ID.
- `{{ service.id }}`: Inserts the service ID.
- `{{ product.title }}`: Inserts the product title.
- `{{ date }}`: Inserts the current date/time (server-side).

### 6. Plans JSON Format (Client Checkout UI) IN DEVELOPMENT
If `Plans JSON` is set, the client checkout page renders selectable plan cards and writes the selected plan’s values into hidden config inputs.

Schema:
- Top-level: array of categories
- Each category: `{ "name": string, "description"?: string, "plans": Plan[] }`
- Each plan supports:
  - Required (recommended): `id`, `name`, `memory`, `disk`, `cpu`
  - Optional: `swap`, `io`, `databases`, `allocations`, `backups`, `price_display`

Example:
```json
[
  {
    "name": "Standard",
    "description": "Most popular",
    "plans": [
      { "id": "basic", "name": "Basic", "memory": 1024, "disk": 5000, "cpu": 100, "allocations": 0, "databases": 0, "backups": 0, "price_display": "$5.00" },
      { "id": "pro", "name": "Pro", "memory": 2048, "disk": 10000, "cpu": 200, "allocations": 1, "databases": 1, "backups": 1, "price_display": "$10.00" }
    ]
  }
]
```

Important:
- `price_display` is **visual only**; it does not change the actual cart price unless you implement pricing via FOSSBilling configurable options.

### 7. “Allow User Configuration at Checkout” (Egg Variables)
In product config you can mark specific Egg variables as client-editable. At checkout:
- Only variables flagged in `variables_configurable` are shown to the client.
- Values are submitted as `config[variables][ENV_NAME]` and used during provisioning.

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

## 🔑 Recommended API Key Permissions
Create an Application API key in the Pterodactyl Admin Panel with at least:
- Users: Read/Write
- Nodes: Read
- Locations: Read
- Servers: Read/Write
- Allocations: Read/Write
- Nests: Read
- Eggs: Read

If you don’t use features like `AUTO_PORT`, you may not need write access to Allocations.

---

## 🧯 Troubleshooting

### “This action is unauthorized” / HTTP 403
Most commonly:
- You used a **Client API key** (`ptlc_...`) in the **Application API Key** field (must be `ptla_...`).
- The Application API key does not have the required permissions (Eggs/Nests/Allocations/Servers).

### Can’t load nodes/locations/eggs in product configuration
- Confirm **Panel URL** is correct and uses HTTPS if your panel enforces it.
- Use the **Debug** tab to verify connectivity.
- Confirm your web server can reach the panel (firewall, DNS).

### AUTO_PORT doesn’t allocate from the range you expect
- Configure **Node Configuration** for the node (host + port range).
- Ensure the node has an Address matching the host/IP you configured.
- Ensure your Application API key can write allocations if allocations must be created.

### General tips
- Keep your API keys scoped to the minimum permissions needed.
- If your panel is behind a firewall, allow outbound traffic from the FOSSBilling server to the panel.
