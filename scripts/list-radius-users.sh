#!/bin/bash

# Bash script to list all RADIUS users in WiFi Hotspot system
# Usage: ./list-radius-users.sh [options]

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to display usage
usage() {
    echo -e "${CYAN}Usage:${NC} $0 [options]"
    echo ""
    echo "Options:"
    echo "  -d, --detailed    Show detailed information including passwords"
    echo "  -c, --count       Show only the count of users"
    echo "  -s, --search      Search for specific username"
    echo "  -h, --help        Display this help message"
    echo ""
    echo "Examples:"
    echo "  $0                    # List all users"
    echo "  $0 -d                 # List with details"
    echo "  $0 -c                 # Show count only"
    echo "  $0 -s john            # Search for 'john'"
    exit 1
}

# Parse command line arguments
DETAILED=false
COUNT_ONLY=false
SEARCH=""

while [[ $# -gt 0 ]]; do
    case $1 in
        -d|--detailed)
            DETAILED=true
            shift
            ;;
        -c|--count)
            COUNT_ONLY=true
            shift
            ;;
        -s|--search)
            SEARCH="$2"
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

echo -e "${CYAN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║           RADIUS Users - WiFi Hotspot System           ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Count only mode
if [ "$COUNT_ONLY" = true ]; then
    COUNT_QUERY="SELECT COUNT(*) FROM radcheck WHERE attribute='Cleartext-Password';"
    USER_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$COUNT_QUERY" | tr -d ' ')
    echo -e "${GREEN}Total RADIUS users: ${WHITE}$USER_COUNT${NC}"
    exit 0
fi

# Build query based on options
if [ -n "$SEARCH" ]; then
    WHERE_CLAUSE="WHERE username LIKE '%$SEARCH%' AND attribute='Cleartext-Password'"
    echo -e "${YELLOW}Searching for: ${WHITE}$SEARCH${NC}"
    echo ""
else
    WHERE_CLAUSE="WHERE attribute='Cleartext-Password'"
fi

if [ "$DETAILED" = true ]; then
    # Detailed view with passwords
    QUERY="SELECT 
        id,
        username,
        value as password,
        op as operator
    FROM radcheck 
    $WHERE_CLAUSE
    ORDER BY username;"
    
    echo -e "${CYAN}ID  | Username          | Password          | Op${NC}"
    echo -e "${CYAN}----+-------------------+-------------------+----${NC}"
    
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" | while IFS='|' read -r id username password op; do
        # Trim whitespace
        id=$(echo "$id" | xargs)
        username=$(echo "$username" | xargs)
        password=$(echo "$password" | xargs)
        op=$(echo "$op" | xargs)
        
        if [ -n "$username" ]; then
            printf "${WHITE}%-4s${NC}| ${GREEN}%-17s${NC} | ${YELLOW}%-17s${NC} | ${BLUE}%-3s${NC}\n" "$id" "$username" "$password" "$op"
        fi
    done
else
    # Simple view without passwords
    QUERY="SELECT 
        id,
        username,
        CASE 
            WHEN LENGTH(value) > 0 THEN '********'
            ELSE 'No password'
        END as password_status
    FROM radcheck 
    $WHERE_CLAUSE
    ORDER BY username;"
    
    echo -e "${CYAN}ID  | Username          | Password Status${NC}"
    echo -e "${CYAN}----+-------------------+------------------${NC}"
    
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" | while IFS='|' read -r id username status; do
        # Trim whitespace
        id=$(echo "$id" | xargs)
        username=$(echo "$username" | xargs)
        status=$(echo "$status" | xargs)
        
        if [ -n "$username" ]; then
            printf "${WHITE}%-4s${NC}| ${GREEN}%-17s${NC} | ${YELLOW}%-17s${NC}\n" "$id" "$username" "$status"
        fi
    done
fi

echo ""

# Show total count
COUNT_QUERY="SELECT COUNT(*) FROM radcheck $WHERE_CLAUSE;"
TOTAL=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$COUNT_QUERY" | tr -d ' ')

if [ -n "$SEARCH" ]; then
    echo -e "${GREEN}Found: ${WHITE}$TOTAL${GREEN} user(s)${NC}"
else
    echo -e "${GREEN}Total: ${WHITE}$TOTAL${GREEN} user(s)${NC}"
fi

echo ""

# Show additional info
if [ "$DETAILED" = false ]; then
    echo -e "${CYAN}💡 Tip: Use -d flag to show passwords${NC}"
fi

echo -e "${CYAN}💡 To create a new user: ${WHITE}./create-radius-user.sh -u username -p password${NC}"
echo -e "${CYAN}💡 To delete a user: ${WHITE}./delete-radius-user.sh -u username${NC}"
