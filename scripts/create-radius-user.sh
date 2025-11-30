#!/bin/bash

# Bash script to create RADIUS users for WiFi Hotspot authentication
# Usage: ./create-radius-user.sh -u username -p password

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# Function to display usage
usage() {
    echo -e "${CYAN}Usage:${NC} $0 -u <username> -p <password>"
    echo ""
    echo "Options:"
    echo "  -u, --username    Username for RADIUS authentication (required)"
    echo "  -p, --password    Password for the user (required)"
    echo "  -h, --help        Display this help message"
    echo ""
    echo "Example:"
    echo "  $0 -u john -p secret123"
    exit 1
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--username)
            USERNAME="$2"
            shift 2
            ;;
        -p|--password)
            PASSWORD="$2"
            shift 2
            ;;
        -h|--help)
            usage
            ;;
        *)
            echo -e "${RED}Error: Unknown option $1${NC}"
            usage
            ;;
    esac
done

# Validate required parameters
if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ]; then
    echo -e "${RED}Error: Username and password are required!${NC}"
    usage
fi

echo -e "${CYAN}Creating RADIUS user: ${WHITE}$USERNAME${NC}"

# Check if user already exists
CHECK_QUERY="SELECT COUNT(*) FROM radcheck WHERE username='$USERNAME';"
EXISTING_USER=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$CHECK_QUERY" | tr -d ' ')

if [ "$EXISTING_USER" -gt 0 ]; then
    echo -e "${RED}Error: User '$USERNAME' already exists!${NC}"
    echo -e "${YELLOW}To update password, use: ./update-radius-password.sh -u '$USERNAME' -p 'newpassword'${NC}"
    exit 1
fi

# Insert new user
INSERT_QUERY="INSERT INTO radcheck (username, attribute, op, value) VALUES ('$USERNAME', 'Cleartext-Password', ':=', '$PASSWORD');"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$INSERT_QUERY" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ User '$USERNAME' created successfully!${NC}"
    echo ""
    echo -e "${CYAN}Login credentials:${NC}"
    echo -e "  Username: ${WHITE}$USERNAME${NC}"
    echo -e "  Password: ${WHITE}$PASSWORD${NC}"
    echo ""
    echo -e "${CYAN}You can now login at: ${WHITE}http://localhost/login${NC}"
else
    echo -e "${RED}❌ Failed to create user!${NC}"
    exit 1
fi
