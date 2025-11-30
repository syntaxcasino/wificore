#!/bin/bash

# Bash script to update RADIUS user password
# Usage: ./update-radius-password.sh -u username -p newpassword

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# Function to display usage
usage() {
    echo -e "${CYAN}Usage:${NC} $0 -u <username> -p <new_password>"
    echo ""
    echo "Options:"
    echo "  -u, --username    Username to update (required)"
    echo "  -p, --password    New password (required)"
    echo "  -h, --help        Display this help message"
    echo ""
    echo "Example:"
    echo "  $0 -u john -p newsecret123"
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
            NEW_PASSWORD="$2"
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
if [ -z "$USERNAME" ] || [ -z "$NEW_PASSWORD" ]; then
    echo -e "${RED}Error: Username and new password are required!${NC}"
    usage
fi

echo -e "${CYAN}Updating password for RADIUS user: ${WHITE}$USERNAME${NC}"

# Check if user exists
CHECK_QUERY="SELECT COUNT(*) FROM radcheck WHERE username='$USERNAME' AND attribute='Cleartext-Password';"
EXISTING_USER=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$CHECK_QUERY" | tr -d ' ')

if [ "$EXISTING_USER" -eq 0 ]; then
    echo -e "${RED}Error: User '$USERNAME' does not exist!${NC}"
    echo -e "${YELLOW}To create a new user, use: ./create-radius-user.sh -u '$USERNAME' -p 'password'${NC}"
    echo -e "${YELLOW}To list all users, use: ./list-radius-users.sh${NC}"
    exit 1
fi

# Update password
UPDATE_QUERY="UPDATE radcheck SET value='$NEW_PASSWORD' WHERE username='$USERNAME' AND attribute='Cleartext-Password';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$UPDATE_QUERY" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Password updated successfully!${NC}"
    echo ""
    echo -e "${CYAN}Updated credentials:${NC}"
    echo -e "  Username: ${WHITE}$USERNAME${NC}"
    echo -e "  New Password: ${WHITE}$NEW_PASSWORD${NC}"
    echo ""
    echo -e "${YELLOW}⚠️  Note: If user is currently logged in, they will need to login again.${NC}"
else
    echo -e "${RED}❌ Failed to update password!${NC}"
    exit 1
fi
