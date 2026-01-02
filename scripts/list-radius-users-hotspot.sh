#!/bin/bash

# WiFi Hotspot - List all RADIUS users (System Admin + Tenant Users)
# Usage: ./list-radius-users-hotspot.sh [options]

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# Function to display usage
usage() {
    echo -e "${CYAN}Usage:${NC} $0 [options]"
    echo ""
    echo "Options:"
    echo "  -d, --detailed       Show detailed information including passwords"
    echo "  -c, --count          Show only the count of users"
    echo "  -s, --search <term>  Search for specific username"
    echo "  -t, --tenant-only    Show only tenant users"
    echo "  -a, --admin-only     Show only system admin users"
    echo "  -h, --help           Display this help message"
    echo ""
    echo "Examples:"
    echo "  $0                    # List all users (system admin + tenant users)"
    echo "  $0 -d                 # List with passwords visible"
    echo "  $0 -c                 # Show count only"
    echo "  $0 -t                 # Show only tenant users"
    echo "  $0 -a                 # Show only system admin"
    echo "  $0 -s john            # Search for 'john'"
    echo "  $0 -d -t              # Show tenant users with passwords"
    exit 1
}

# Parse command line arguments
DETAILED=false
COUNT_ONLY=false
SEARCH=""
TENANT_ONLY=false
ADMIN_ONLY=false

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
        -t|--tenant-only)
            TENANT_ONLY=true
            shift
            ;;
        -a|--admin-only)
            ADMIN_ONLY=true
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

echo ""
echo -e "${CYAN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║       FreeRADIUS Users - WiFi Hotspot Multi-Tenant System     ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Build WHERE clause based on filters
WHERE_CLAUSE="WHERE rc.attribute='Cleartext-Password'"

if [ "$TENANT_ONLY" = true ]; then
    WHERE_CLAUSE="$WHERE_CLAUSE AND m.username IS NOT NULL"
    echo -e "${YELLOW}🔍 Filter: Tenant Users Only${NC}"
    echo ""
elif [ "$ADMIN_ONLY" = true ]; then
    WHERE_CLAUSE="$WHERE_CLAUSE AND m.username IS NULL"
    echo -e "${YELLOW}🔍 Filter: System Admin Only${NC}"
    echo ""
fi

if [ -n "$SEARCH" ]; then
    WHERE_CLAUSE="$WHERE_CLAUSE AND rc.username LIKE '%$SEARCH%'"
    echo -e "${YELLOW}🔍 Searching for: ${WHITE}$SEARCH${NC}"
    echo ""
fi

# Count only mode
if [ "$COUNT_ONLY" = true ]; then
    COUNT_QUERY="
    SELECT 
        COUNT(*) FILTER (WHERE m.username IS NULL) as system_admins,
        COUNT(*) FILTER (WHERE m.username IS NOT NULL) as tenant_users,
        COUNT(*) as total
    FROM radcheck rc
    LEFT JOIN radius_user_schema_mapping m ON rc.username = m.username
    $WHERE_CLAUSE;"
    
    STATS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$COUNT_QUERY")
    SYSTEM_ADMINS=$(echo "$STATS" | awk -F'|' '{print $1}' | xargs)
    TENANT_USERS=$(echo "$STATS" | awk -F'|' '{print $2}' | xargs)
    TOTAL=$(echo "$STATS" | awk -F'|' '{print $3}' | xargs)
    
    echo -e "${CYAN}📊 Statistics:${NC}"
    echo -e "${WHITE}   System Admins:  ${GREEN}$SYSTEM_ADMINS${NC}"
    echo -e "${WHITE}   Tenant Users:   ${GREEN}$TENANT_USERS${NC}"
    echo -e "${WHITE}   Total Users:    ${GREEN}$TOTAL${NC}"
    echo ""
    exit 0
fi

if [ "$DETAILED" = true ]; then
    # Show System Admin Users First
    if [ "$TENANT_ONLY" = false ]; then
        echo -e "${MAGENTA}╔════════════════════════════════════════════════════════════════════════════════════════════════════╗${NC}"
        echo -e "${MAGENTA}║                                    SYSTEM ADMIN USERS                                              ║${NC}"
        echo -e "${MAGENTA}╚════════════════════════════════════════════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        
        ADMIN_QUERY="
        SELECT 
            u.id::text as id,
            COALESCE(u.email, u.username) as username,
            COALESCE(rc.value, 'No Password') as password,
            'public' as schema,
            u.role as role,
            u.is_active::text as active,
            u.created_at::date as created
        FROM users u
        LEFT JOIN radcheck rc ON u.username = rc.username AND rc.attribute = 'Cleartext-Password'
        LEFT JOIN radius_user_schema_mapping m ON u.username = m.username
        WHERE m.username IS NULL AND u.tenant_id IS NULL
        ORDER BY u.username;"
        
        echo -e "${CYAN}Username                    | Password          | Role          | Active | Created    ${NC}"
        echo -e "${CYAN}----------------------------+-------------------+---------------+--------+------------${NC}"
        
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$ADMIN_QUERY" | while IFS='|' read -r id username password schema role active created; do
            username=$(echo "$username" | xargs)
            password=$(echo "$password" | xargs)
            role=$(echo "$role" | xargs)
            active=$(echo "$active" | xargs)
            created=$(echo "$created" | xargs)
            
            if [ -n "$username" ]; then
                printf "${GREEN}%-27s${NC} | ${YELLOW}%-17s${NC} | ${BLUE}%-13s${NC} | ${WHITE}%-6s${NC} | ${CYAN}%-10s${NC}\n" \
                    "$username" "$password" "$role" "$active" "$created"
            fi
        done
        
        echo ""
        echo ""
    fi
    
    # Show Tenant Users with detailed tenant information
    if [ "$ADMIN_ONLY" = false ]; then
        echo -e "${BLUE}╔════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗${NC}"
        echo -e "${BLUE}║                              TENANT USERS (Hotspot Users) - DATA LEAK VERIFICATION                                     ║${NC}"
        echo -e "${BLUE}╚════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        
        # Get all tenants first
        TENANTS_QUERY="SELECT id, name, schema_name FROM tenants ORDER BY name;"
        
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$TENANTS_QUERY" | while IFS='|' read -r tenant_id tenant_name schema_name; do
            tenant_id=$(echo "$tenant_id" | xargs)
            tenant_name=$(echo "$tenant_name" | xargs)
            schema_name=$(echo "$schema_name" | xargs)
            
            if [ -n "$schema_name" ]; then
                echo -e "${YELLOW}═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════${NC}"
                echo -e "${YELLOW}Tenant: ${WHITE}$tenant_name${NC}"
                echo -e "${YELLOW}Schema: ${CYAN}$schema_name${NC}"
                echo -e "${YELLOW}Tenant ID: ${MAGENTA}$tenant_id${NC}"
                echo -e "${YELLOW}═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════${NC}"
                echo ""
                
                # Get hotspot users for this tenant
                USERS_QUERY="
                SELECT 
                    u.username as username,
                    SUBSTRING(u.name, 1, 25) as name,
                    u.email as email,
                    u.role as role,
                    COALESCE(rc.value, 'No Password') as password,
                    u.account_balance::text as balance,
                    u.is_active::text as active
                FROM ${schema_name}.users u
                LEFT JOIN ${schema_name}.radcheck rc ON u.username = rc.username AND rc.attribute = 'Cleartext-Password'
                WHERE u.tenant_id = '$tenant_id'
                ORDER BY u.username
                LIMIT 50;"
                
                # Check if schema exists
                SCHEMA_EXISTS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = '$schema_name');")
                
                if echo "$SCHEMA_EXISTS" | grep -q "t"; then
                    echo -e "${CYAN}Username        | Name                      | Email                     | Role          | Password          | Balance | Active${NC}"
                    echo -e "${CYAN}----------------+---------------------------+---------------------------+---------------+-------------------+---------+-------${NC}"
                    
                    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$USERS_QUERY" 2>/dev/null | while IFS='|' read -r username name email role password balance active; do
                        username=$(echo "$username" | xargs)
                        name=$(echo "$name" | xargs)
                        email=$(echo "$email" | xargs)
                        role=$(echo "$role" | xargs)
                        password=$(echo "$password" | xargs)
                        balance=$(echo "$balance" | xargs)
                        active=$(echo "$active" | xargs)
                        
                        if [ -n "$username" ]; then
                            printf "${GREEN}%-15s${NC} | ${WHITE}%-25s${NC} | ${CYAN}%-25s${NC} | ${BLUE}%-13s${NC} | ${YELLOW}%-17s${NC} | ${MAGENTA}%-7s${NC} | ${WHITE}%-6s${NC}\n" \
                                "$username" "$name" "$email" "$role" "$password" "$balance" "$active"
                        fi
                    done
                    
                    # Count users in this tenant
                    USER_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM ${schema_name}.users WHERE tenant_id = '$tenant_id';" 2>/dev/null | xargs)
                    
                    echo ""
                    echo -e "${WHITE}Total users in this tenant: ${GREEN}$USER_COUNT${NC}"
                else
                    echo -e "${RED}⚠️  Schema '$schema_name' does not exist yet${NC}"
                fi
                
                echo ""
                echo ""
            fi
        done
    fi
else
    # Simple list without passwords
    echo -e "${CYAN}Username                    | Role          | Schema        | Active | Created    ${NC}"
    echo -e "${CYAN}----------------------------+---------------+---------------+--------+------------${NC}"
    
    SIMPLE_QUERY="
    SELECT 
        rc.username,
        COALESCE(u.role, 'unknown') as role,
        COALESCE(m.schema_name, 'public') as schema,
        COALESCE(u.is_active::text, 'unknown') as active,
        COALESCE(u.created_at::date::text, 'unknown') as created
    FROM radcheck rc
    LEFT JOIN radius_user_schema_mapping m ON rc.username = m.username
    LEFT JOIN users u ON rc.username = u.username
    $WHERE_CLAUSE
    ORDER BY rc.username;"
    
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$SIMPLE_QUERY" | while IFS='|' read -r username role schema active created; do
        username=$(echo "$username" | xargs)
        role=$(echo "$role" | xargs)
        schema=$(echo "$schema" | xargs)
        active=$(echo "$active" | xargs)
        created=$(echo "$created" | xargs)
        
        if [ -n "$username" ]; then
            printf "${GREEN}%-27s${NC} | ${BLUE}%-13s${NC} | ${CYAN}%-13s${NC} | ${WHITE}%-6s${NC} | ${YELLOW}%-10s${NC}\n" \
                "$username" "$role" "$schema" "$active" "$created"
        fi
    done
fi

echo ""
echo -e "${CYAN}📊 Statistics:${NC}"

# Get statistics
STATS_QUERY="
SELECT 
    COUNT(*) FILTER (WHERE m.username IS NULL) as system_admins,
    COUNT(*) FILTER (WHERE m.username IS NOT NULL) as tenant_users,
    (SELECT COUNT(*) FROM users WHERE tenant_id IS NOT NULL AND username NOT IN (SELECT username FROM radcheck WHERE attribute = 'Cleartext-Password')) as users_without_radius,
    COUNT(*) as total_radius,
    (SELECT COUNT(*) FROM users) as total_users
FROM radcheck rc
LEFT JOIN radius_user_schema_mapping m ON rc.username = m.username
WHERE rc.attribute = 'Cleartext-Password';"

STATS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$STATS_QUERY")
SYSTEM_ADMINS=$(echo "$STATS" | awk -F'|' '{print $1}' | xargs)
TENANT_USERS=$(echo "$STATS" | awk -F'|' '{print $2}' | xargs)
USERS_WITHOUT_RADIUS=$(echo "$STATS" | awk -F'|' '{print $3}' | xargs)
TOTAL_RADIUS=$(echo "$STATS" | awk -F'|' '{print $4}' | xargs)
TOTAL_USERS=$(echo "$STATS" | awk -F'|' '{print $5}' | xargs)

echo -e "${WHITE}   System Admins:           ${GREEN}$SYSTEM_ADMINS${NC}"
echo -e "${WHITE}   Tenant Users (RADIUS):   ${GREEN}$TENANT_USERS${NC}"
echo -e "${WHITE}   Users Without RADIUS:    ${YELLOW}$USERS_WITHOUT_RADIUS${NC}"
echo -e "${WHITE}   Total RADIUS Users:      ${GREEN}$TOTAL_RADIUS${NC}"
echo -e "${WHITE}   Total Users:             ${GREEN}$TOTAL_USERS${NC}"

echo ""
echo -e "${CYAN}💡 To create a new user: ./create-radius-user.sh -u username -p password${NC}"
echo -e "${CYAN}💡 To delete a user: ./delete-radius-user.sh -u username${NC}"
echo ""
