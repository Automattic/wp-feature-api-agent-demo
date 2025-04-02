#!/bin/bash

# WP ReAct Agent Plugin Deployment Script
# This script deploys the plugin to a running Docker WordPress container

# Configuration
CONTAINER_NAME="wp-feature-api-wordpress-1"
PLUGIN_DIR_NAME="wp-react-agent"
REMOTE_PLUGINS_DIR="/var/www/html/wp-content/plugins"
LOCAL_PLUGIN_DIR=$(pwd)

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to display colorful status messages
function echo_status() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo_status "${RED}" "Error: Docker is not running"
    exit 1
fi

# Check if the container exists and is running
if ! docker ps | grep -q "${CONTAINER_NAME}"; then
    echo_status "${RED}" "Error: Container ${CONTAINER_NAME} is not running"
    echo_status "${YELLOW}" "Available containers:"
    docker ps --format "{{.Names}}"
    exit 1
fi

echo_status "${GREEN}" "=== Starting WP ReAct Agent Plugin Deployment ==="

# Check for necessary files
if [ ! -f "${LOCAL_PLUGIN_DIR}/plugin.php" ]; then
    echo_status "${RED}" "Error: plugin.php not found in current directory"
    exit 1
fi

# Create a temporary directory for the plugin
TMP_DIR=$(mktemp -d)
PLUGIN_TMP_DIR="${TMP_DIR}/${PLUGIN_DIR_NAME}"

echo_status "${YELLOW}" "Creating temporary copy of plugin at ${PLUGIN_TMP_DIR}"
mkdir -p "${PLUGIN_TMP_DIR}"

# Copy essential files to temp directory
cp -r "${LOCAL_PLUGIN_DIR}/assets" "${PLUGIN_TMP_DIR}/"
cp -r "${LOCAL_PLUGIN_DIR}/features" "${PLUGIN_TMP_DIR}/"
cp "${LOCAL_PLUGIN_DIR}/agent-core.php" "${PLUGIN_TMP_DIR}/"
cp "${LOCAL_PLUGIN_DIR}/plugin.php" "${PLUGIN_TMP_DIR}/"
cp "${LOCAL_PLUGIN_DIR}/README.md" "${PLUGIN_TMP_DIR}/" 2>/dev/null || true

# Remove any existing plugin directory in the container
echo_status "${YELLOW}" "Removing existing plugin directory in container..."
docker exec "${CONTAINER_NAME}" rm -rf "${REMOTE_PLUGINS_DIR}/${PLUGIN_DIR_NAME}"

# Copy the plugin files to the container
echo_status "${YELLOW}" "Copying plugin files to container..."
docker cp "${PLUGIN_TMP_DIR}" "${CONTAINER_NAME}:${REMOTE_PLUGINS_DIR}/"

# Fix permissions
echo_status "${YELLOW}" "Fixing permissions..."
docker exec "${CONTAINER_NAME}" chown -R www-data:www-data "${REMOTE_PLUGINS_DIR}/${PLUGIN_DIR_NAME}"

# Clean up the temporary directory
echo_status "${YELLOW}" "Cleaning up temporary files..."
rm -rf "${TMP_DIR}"

echo_status "${GREEN}" "=== Deployment Complete ==="
echo_status "${GREEN}" "Plugin deployed to: ${REMOTE_PLUGINS_DIR}/${PLUGIN_DIR_NAME}"
echo_status "${YELLOW}" "Next steps:"
echo_status "${YELLOW}" "1. Activate the plugin in WordPress admin"
echo_status "${YELLOW}" "2. Configure AI Services and Feature API plugins if not already done"
echo_status "${YELLOW}" "3. Access WordPress admin at http://localhost:8787/wp-admin/"

# Optional: Add plugin activation via WP-CLI
# Uncomment the following lines if you want to automatically activate the plugin
# echo_status "${YELLOW}" "Activating plugin via WP-CLI..."
# docker exec "${CONTAINER_NAME}" wp plugin activate "${PLUGIN_DIR_NAME}" --allow-root
# if [ $? -eq 0 ]; then
#     echo_status "${GREEN}" "Plugin activated successfully!"
# else
#     echo_status "${RED}" "Failed to activate plugin. Please activate manually."
# fi

exit 0
