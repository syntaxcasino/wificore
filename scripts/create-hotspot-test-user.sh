#!/bin/bash

# Bash script to create a complete Hotspot test user
# Creates user in both application database and RADIUS
# Usage: ./create-hotspot-test-user.sh [options]

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default values
USERNAME=""
PASSWORD=""
EMAIL=""
PHONE=""
MAC_ADDRESS=""
PACKAGE_ID=""
AUTO_CREATE_SUBSCRIPTION="false"
SKIP_EMAIL_VERIFICATION="false"

# Function to display usage
usage() {
    echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║         Create Hotspot Test User                              ║${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${WHITE}Usage:${NC} $0 [options]"
    echo ""
    echo -e "${WHITE}Options:${NC}"
    echo -e "  ${CYAN}-u, --username${NC}      Username for hotspot login (default: testuser)"
    echo -e "  ${CYAN}-p, --password${NC}      Password (default: Test@123)"
    echo -e "  ${CYAN}-e, --email${NC}         Email address (default: testuser@example.com)"
    echo -e "  ${CYAN}-m, --mac${NC}           MAC address (default: random)"
    echo -e "  ${CYAN}-n, --phone${NC}         Phone number (default: +254700000000)"
    echo -e "  ${CYAN}-k, --package${NC}       Package ID for subscription (optional)"
    echo -e "  ${CYAN}-s, --subscription${NC}  Auto-create active subscription"
    echo -e "  ${CYAN}-v, --verify${NC}        Skip email verification"
    echo -e "  ${CYAN}-h, --help${NC}          Display this help message"
    echo ""
    echo -e "${WHITE}Examples:${NC}"
    echo -e "  ${YELLOW}# Create basic test user${NC}"
    echo -e "  $0"
    echo ""
    echo -e "  ${YELLOW}# Create user with custom credentials${NC}"
    echo -e "  $0 -u john -p Secret123 -e john@test.com"
    echo ""
    echo -e "  ${YELLOW}# Create user with active subscription${NC}"
    echo -e "  $0 -u premium -p Test@123 -k 1 -s -v"
    echo ""
    exit 1
}

# Function to generate random MAC address
generate_mac() {
    printf '%02X:%02X:%02X:%02X:%02X:%02X\n' $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256))
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
        -e|--email)
            EMAIL="$2"
            shift 2
            ;;
        -m|--mac)
            MAC_ADDRESS="$2"
            shift 2
            ;;
        -n|--phone)
            PHONE="$2"
            shift 2
            ;;
        -k|--package)
            PACKAGE_ID="$2"
            shift 2
            ;;
        -s|--subscription)
            AUTO_CREATE_SUBSCRIPTION="true"
            shift
            ;;
        -v|--verify)
            SKIP_EMAIL_VERIFICATION="true"
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

# Set defaults if not provided
USERNAME=${USERNAME:-"testuser"}
PASSWORD=${PASSWORD:-"Test@123"}
EMAIL=${EMAIL:-"testuser@example.com"}
PHONE=${PHONE:-"+254700000000"}
MAC_ADDRESS=${MAC_ADDRESS:-$(generate_mac)}

echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║         Creating Hotspot Test User                            ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Display configuration
echo -e "${BLUE}Configuration:${NC}"
echo -e "  Username:        ${WHITE}$USERNAME${NC}"
echo -e "  Password:        ${WHITE}$PASSWORD${NC}"
echo -e "  Email:           ${WHITE}$EMAIL${NC}"
echo -e "  Phone:           ${WHITE}$PHONE${NC}"
echo -e "  MAC Address:     ${WHITE}$MAC_ADDRESS${NC}"
if [ -n "$PACKAGE_ID" ]; then
    echo -e "  Package ID:      ${WHITE}$PACKAGE_ID${NC}"
fi
echo -e "  Create Sub:      ${WHITE}$AUTO_CREATE_SUBSCRIPTION${NC}"
echo -e "  Skip Verify:     ${WHITE}$SKIP_EMAIL_VERIFICATION${NC}"
echo ""

# Step 1: Check if user already exists in application database
echo -e "${CYAN}[1/5]${NC} Checking if user exists..."
CHECK_USER_QUERY="SELECT COUNT(*) FROM users WHERE email='$EMAIL' OR username='$USERNAME';"
EXISTING_USER=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$CHECK_USER_QUERY" | tr -d ' ')

if [ "$EXISTING_USER" -gt 0 ]; then
    echo -e "${RED}❌ Error: User with email '$EMAIL' or username '$USERNAME' already exists!${NC}"
    echo -e "${YELLOW}Tip: Use a different username/email or delete the existing user first.${NC}"
    exit 1
fi
echo -e "${GREEN}✅ User does not exist${NC}"
echo ""

# Step 2: Check if user exists in RADIUS
echo -e "${CYAN}[2/5]${NC} Checking RADIUS..."
CHECK_RADIUS_QUERY="SELECT COUNT(*) FROM radcheck WHERE username='$USERNAME';"
EXISTING_RADIUS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$CHECK_RADIUS_QUERY" | tr -d ' ')

if [ "$EXISTING_RADIUS" -gt 0 ]; then
    echo -e "${RED}❌ Error: RADIUS user '$USERNAME' already exists!${NC}"
    exit 1
fi
echo -e "${GREEN}✅ RADIUS user does not exist${NC}"
echo ""

# Step 3: Create user in application database
echo -e "${CYAN}[3/5]${NC} Creating application user..."

# Generate UUID for user
USER_ID=$(docker exec traidnet-backend php -r "echo (string) Illuminate\Support\Str::uuid();")

# Hash password using Laravel
HASHED_PASSWORD=$(docker exec traidnet-backend php -r "require '/var/www/html/vendor/autoload.php'; echo password_hash('$PASSWORD', PASSWORD_BCRYPT);")

# Set email verification
if [ "$SKIP_EMAIL_VERIFICATION" = "true" ]; then
    EMAIL_VERIFIED_AT="NOW()"
else
    EMAIL_VERIFIED_AT="NULL"
fi

# Insert user
INSERT_USER_QUERY="
INSERT INTO users (id, name, username, email, password, phone_number, role, email_verified_at, created_at, updated_at)
VALUES (
    '$USER_ID',
    '$USERNAME',
    '$USERNAME',
    '$EMAIL',
    '$HASHED_PASSWORD',
    '$PHONE',
    'hotspot_user',
    $EMAIL_VERIFIED_AT,
    NOW(),
    NOW()
);
"

docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$INSERT_USER_QUERY" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Application user created successfully!${NC}"
else
    echo -e "${RED}❌ Failed to create application user!${NC}"
    exit 1
fi
echo ""

# Step 4: Create RADIUS user
echo -e "${CYAN}[4/5]${NC} Creating RADIUS user..."

INSERT_RADIUS_QUERY="
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('$USERNAME', 'Cleartext-Password', ':=', '$PASSWORD');
"

docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$INSERT_RADIUS_QUERY" > /dev/null 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ RADIUS user created successfully!${NC}"
else
    echo -e "${RED}❌ Failed to create RADIUS user!${NC}"
    exit 1
fi
echo ""

# Step 5: Create subscription (if requested)
if [ "$AUTO_CREATE_SUBSCRIPTION" = "true" ]; then
    echo -e "${CYAN}[5/5]${NC} Creating active subscription..."
    
    # Get package details or use first available package
    if [ -z "$PACKAGE_ID" ]; then
        PACKAGE_ID=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT id FROM packages LIMIT 1;" | tr -d ' ')
        if [ -z "$PACKAGE_ID" ]; then
            echo -e "${YELLOW}⚠️  No packages found. Skipping subscription creation.${NC}"
        else
            echo -e "${YELLOW}Using first available package: $PACKAGE_ID${NC}"
        fi
    fi
    
    if [ -n "$PACKAGE_ID" ]; then
        # Get package duration
        PACKAGE_INFO=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT duration_value, duration_type FROM packages WHERE id='$PACKAGE_ID';" | tr -d ' ')
        DURATION_VALUE=$(echo $PACKAGE_INFO | cut -d'|' -f1)
        DURATION_TYPE=$(echo $PACKAGE_INFO | cut -d'|' -f2)
        
        # Calculate end time based on duration
        if [ "$DURATION_TYPE" = "hours" ]; then
            END_TIME="NOW() + INTERVAL '$DURATION_VALUE hours'"
        elif [ "$DURATION_TYPE" = "days" ]; then
            END_TIME="NOW() + INTERVAL '$DURATION_VALUE days'"
        else
            END_TIME="NOW() + INTERVAL '30 days'" # Default to 30 days
        fi
        
        # Generate subscription ID
        SUB_ID=$(docker exec traidnet-backend php -r "echo (string) Illuminate\Support\Str::uuid();")
        
        # Create subscription
        INSERT_SUB_QUERY="
        INSERT INTO user_subscriptions (
            id, user_id, package_id, mac_address, start_time, end_time, 
            status, mikrotik_username, mikrotik_password, created_at, updated_at
        )
        VALUES (
            '$SUB_ID',
            '$USER_ID',
            '$PACKAGE_ID',
            '$MAC_ADDRESS',
            NOW(),
            $END_TIME,
            'active',
            '$USERNAME',
            '$PASSWORD',
            NOW(),
            NOW()
        );
        "
        
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "$INSERT_SUB_QUERY" > /dev/null 2>&1
        
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✅ Active subscription created!${NC}"
        else
            echo -e "${YELLOW}⚠️  Failed to create subscription (user still created)${NC}"
        fi
    fi
else
    echo -e "${CYAN}[5/5]${NC} Skipping subscription creation..."
fi
echo ""

# Display summary
echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║         Test User Created Successfully!                       ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${GREEN}✅ Hotspot test user created successfully!${NC}"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}Login Credentials:${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "  ${CYAN}Username:${NC}        ${WHITE}$USERNAME${NC}"
echo -e "  ${CYAN}Password:${NC}        ${WHITE}$PASSWORD${NC}"
echo -e "  ${CYAN}Email:${NC}           ${WHITE}$EMAIL${NC}"
echo -e "  ${CYAN}Phone:${NC}           ${WHITE}$PHONE${NC}"
echo -e "  ${CYAN}MAC Address:${NC}     ${WHITE}$MAC_ADDRESS${NC}"
echo -e "  ${CYAN}User ID:${NC}         ${WHITE}$USER_ID${NC}"
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}Access URLs:${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "  ${CYAN}Hotspot Login:${NC}   ${WHITE}http://localhost/login${NC}"
echo -e "  ${CYAN}Admin Panel:${NC}     ${WHITE}http://localhost/admin${NC}"
echo ""

if [ "$SKIP_EMAIL_VERIFICATION" = "false" ]; then
    echo -e "${YELLOW}⚠️  Email verification required!${NC}"
    echo -e "${YELLOW}Run this to bypass: ./bypass-email-verification.sh -e $EMAIL${NC}"
    echo ""
fi

if [ "$AUTO_CREATE_SUBSCRIPTION" = "true" ] && [ -n "$PACKAGE_ID" ]; then
    echo -e "${GREEN}✅ Active subscription created - user can login immediately!${NC}"
    echo ""
fi

echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}Testing:${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo -e "  ${CYAN}Test RADIUS:${NC}"
echo -e "    radtest $USERNAME $PASSWORD localhost 0 testing123"
echo ""
echo -e "  ${CYAN}View User:${NC}"
echo -e "    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \"SELECT * FROM users WHERE email='$EMAIL';\""
echo ""
echo -e "  ${CYAN}View RADIUS:${NC}"
echo -e "    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \"SELECT * FROM radcheck WHERE username='$USERNAME';\""
echo ""
echo -e "${GREEN}Done! 🎉${NC}"
echo ""
