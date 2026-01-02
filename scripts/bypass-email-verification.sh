#!/bin/bash

# Bypass Email Verification Script
# Usage: ./bypass-email-verification.sh [email|username|all]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Database connection details
DB_CONTAINER="${DB_CONTAINER:-traidnet-postgres}"
DB_NAME="${DB_NAME:-wifi_hotspot}"
DB_USER="${DB_USER:-admin}"

echo -e "${BLUE}=========================================="
echo "TraidNet - Email Verification Bypass"
echo -e "==========================================${NC}"
echo ""

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed or not in PATH${NC}"
    exit 1
fi

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    echo -e "${RED}Error: Container '${DB_CONTAINER}' is not running${NC}"
    echo "Start it with: docker-compose up -d"
    exit 1
fi

# Check if argument provided
if [ $# -eq 0 ]; then
    echo -e "${YELLOW}Usage:${NC}"
    echo "  $0 <email>           - Verify specific user by email"
    echo "  $0 username:<user>   - Verify specific user by username"
    echo "  $0 all               - Verify all unverified users"
    echo ""
    echo -e "${YELLOW}Examples:${NC}"
    echo "  $0 john@example.com"
    echo "  $0 username:johndoe"
    echo "  $0 all"
    exit 1
fi

IDENTIFIER=$1

# Function to execute SQL via Docker
execute_sql() {
    local sql=$1
    local result=$(docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -t -c "$sql" 2>&1)
    
    # Check for errors
    if echo "$result" | grep -q "error:"; then
        echo -e "${RED}Database error: $result${NC}"
        exit 1
    fi
    
    echo "$result"
}

# Function to verify by email
verify_by_email() {
    local email=$1
    
    echo -e "${BLUE}Checking user with email: ${email}${NC}"
    
    # Check if user exists
    local user_exists=$(execute_sql "SELECT COUNT(*) FROM users WHERE email = '${email}';")
    user_exists=$(echo "$user_exists" | tr -d ' ')
    
    if [ "$user_exists" -eq 0 ] 2>/dev/null || [ -z "$user_exists" ]; then
        echo -e "${RED}✗ User with email '${email}' not found.${NC}"
        exit 1
    fi
    
    # Check if already verified
    local is_verified=$(execute_sql "SELECT email_verified_at IS NOT NULL FROM users WHERE email = '${email}';")
    
    if [ "$is_verified" = "t" ]; then
        echo -e "${YELLOW}⚠ User '${email}' is already verified.${NC}"
        exit 0
    fi
    
    # Verify the email
    execute_sql "UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE email = '${email}';"
    
    # Get user details
    local user_info=$(execute_sql "SELECT name, username, email FROM users WHERE email = '${email}';")
    
    echo -e "${GREEN}✓ Email verified successfully!${NC}"
    echo -e "${GREEN}User: ${user_info}${NC}"
}

# Function to verify by username
verify_by_username() {
    local username=$1
    
    echo -e "${BLUE}Checking user with username: ${username}${NC}"
    
    # Check if user exists
    local user_exists=$(execute_sql "SELECT COUNT(*) FROM users WHERE username = '${username}';")
    
    if [ "$user_exists" -eq 0 ]; then
        echo -e "${RED}✗ User with username '${username}' not found.${NC}"
        exit 1
    fi
    
    # Check if already verified
    local is_verified=$(execute_sql "SELECT email_verified_at IS NOT NULL FROM users WHERE username = '${username}';")
    
    if [ "$is_verified" = "t" ]; then
        echo -e "${YELLOW}⚠ User '${username}' is already verified.${NC}"
        exit 0
    fi
    
    # Verify the email
    execute_sql "UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE username = '${username}';"
    
    # Get user details
    local user_info=$(execute_sql "SELECT name, username, email FROM users WHERE username = '${username}';")
    
    echo -e "${GREEN}✓ Email verified successfully!${NC}"
    echo -e "${GREEN}User: ${user_info}${NC}"
}

# Function to verify all users
verify_all() {
    echo -e "${BLUE}Finding all unverified users...${NC}"
    
    # Count unverified users
    local count=$(execute_sql "SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL;")
    
    if [ "$count" -eq 0 ]; then
        echo -e "${YELLOW}No unverified users found.${NC}"
        exit 0
    fi
    
    echo -e "${YELLOW}Found ${count} unverified user(s).${NC}"
    echo ""
    
    # Show unverified users
    echo -e "${BLUE}Unverified users:${NC}"
    execute_sql "SELECT id, name, username, email, created_at FROM users WHERE email_verified_at IS NULL;"
    echo ""
    
    # Confirm action
    read -p "Do you want to verify all these users? (yes/no): " confirm
    
    if [ "$confirm" != "yes" ]; then
        echo -e "${YELLOW}Operation cancelled.${NC}"
        exit 0
    fi
    
    # Verify all users
    execute_sql "UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE email_verified_at IS NULL;"
    
    echo -e "${GREEN}✓ All ${count} user(s) verified successfully!${NC}"
}

# Main logic
if [ "$IDENTIFIER" = "all" ]; then
    verify_all
elif [[ "$IDENTIFIER" == username:* ]]; then
    username="${IDENTIFIER#username:}"
    verify_by_username "$username"
else
    verify_by_email "$IDENTIFIER"
fi

echo ""
echo -e "${GREEN}=========================================="
echo "Verification Complete!"
echo -e "==========================================${NC}"
