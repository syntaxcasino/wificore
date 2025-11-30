#!/bin/bash

# Bash script to delete RADIUS users from WiFi Hotspot authentication
# Usage: ./delete-radius-user.sh -u username

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# Function to display usage
usage() {
    echo -e "${CYAN}Usage:${NC} $0 -u <username> [-f]"
    echo ""
    echo "Options:"
    echo "  -u, --username    Username to delete (required)"
    echo "  -f, --force       Skip confirmation prompt"
    echo "  -h, --help        Display this help message"
    echo ""
    echo "Example:"
    echo "  $0 -u john"
    echo "  $0 -u john -f"
    exit 1
}

# Parse command line arguments
FORCE=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--username)
            USERNAME="$2"
            shift 2
            ;;
        -f|--force)
            FORCE=true
            shift
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
if [ -z "$USERNAME" ]; then
    echo -e "${RED}Error: Username is required!${NC}"
    usage
fi

echo -e "${CYAN}Deleting RADIUS user: ${WHITE}$USERNAME${NC}"

# Check if user exists
CHECK_QUERY="SELECT COUNT(*) FROM radcheck WHERE username='$USERNAME';"
EXISTING_USER=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$CHECK_QUERY" | tr -d ' ')

if [ "$EXISTING_USER" -eq 0 ]; then
    echo -e "${RED}Error: User '$USERNAME' does not exist!${NC}"
    echo -e "${YELLOW}To list all users, use: ./list-radius-users.sh${NC}"
    exit 1
fi

# Confirmation prompt (unless -f flag is used)
if [ "$FORCE" = false ]; then
    echo -e "${YELLOW}Are you sure you want to delete user '$USERNAME'? (y/N)${NC}"
    read -r CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        echo -e "${CYAN}Operation cancelled.${NC}"
        exit 0
    fi
fi

# Delete user from radcheck
DELETE_RADCHECK="DELETE FROM radcheck WHERE username='$USERNAME';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$DELETE_RADCHECK" > /dev/null 2>&1

# Delete user from radreply (if exists)
DELETE_RADREPLY="DELETE FROM radreply WHERE username='$USERNAME';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$DELETE_RADREPLY" > /dev/null 2>&1

# Delete user from radusergroup (if exists)
DELETE_RADUSERGROUP="DELETE FROM radusergroup WHERE username='$USERNAME';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$DELETE_RADUSERGROUP" > /dev/null 2>&1

# Delete Laravel user (if exists)
DELETE_LARAVEL_USER="DELETE FROM users WHERE username='$USERNAME';"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$DELETE_LARAVEL_USER" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ User '$USERNAME' deleted successfully!${NC}"
    echo ""
    echo -e "${CYAN}Deleted from:${NC}"
    echo -e "  • RADIUS authentication (radcheck)"
    echo -e "  • RADIUS reply attributes (radreply)"
    echo -e "  • RADIUS user groups (radusergroup)"
    echo -e "  • Laravel users table"
else
    echo -e "${RED}❌ Failed to delete user!${NC}"
    exit 1
fi
