#!/bin/bash

# Bash script to list all RADIUS users (System Admin + Tenant Users)
# Usage: ./list-radius-users.sh [options]

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
echo -e "${CYAN}║       FreeRADIUS Users - Multi-Tenant System                   ║${NC}"
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
            u.email as username,
            COALESCE(rc.value, 'No Password') as password,
            'public' as schema,
            COALESCE(r.name, 'system_admin') as role,
            u.is_active::text as active,
            u.created_at::date as created
        FROM users u
        LEFT JOIN radcheck rc ON u.email = rc.username AND rc.attribute = 'Cleartext-Password'
        LEFT JOIN radius_user_schema_mapping m ON u.email = m.username
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE m.username IS NULL
        ORDER BY u.email;"
        
        echo -e "${CYAN}Email/Username              | Password          | Role          | Active | Created    ${NC}"
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
        echo -e "${BLUE}║                              TENANT USERS (Farmers & Employees) - DATA LEAK VERIFICATION                                ║${NC}"
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
                
                # Get farmers for this tenant
                FARMERS_QUERY="
                SELECT 
                    f.farmer_code as code,
                    SUBSTRING(f.first_name || ' ' || f.last_name, 1, 20) as name,
                    u.username as username,
                    f.tenant_id as db_tenant_id,
                    CASE WHEN f.tenant_id = '$tenant_id' THEN 'OK' ELSE 'LEAK!' END as tenant_check
                FROM farmers f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.deleted_at IS NULL
                ORDER BY f.farmer_code;"
                
                FARMER_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; SELECT COUNT(*) FROM farmers WHERE deleted_at IS NULL;" | grep -E '^[[:space:]]*[0-9]+[[:space:]]*$' | xargs)
                
                if [ -n "$FARMER_COUNT" ] && [ "$FARMER_COUNT" -gt 0 ] 2>/dev/null; then
                    echo -e "${GREEN}📋 FARMERS (${FARMER_COUNT}):${NC}"
                    echo -e "${CYAN}Code            | Name                 | Username      | Password      | Tenant Check${NC}"
                    echo -e "${CYAN}----------------+----------------------+---------------+---------------+--------------${NC}"
                    
                    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; $FARMERS_QUERY" | tail -n +2 | while IFS='|' read -r code name username db_tenant_id tenant_check; do
                        code=$(echo "$code" | xargs)
                        name=$(echo "$name" | xargs)
                        username=$(echo "$username" | xargs)
                        db_tenant_id=$(echo "$db_tenant_id" | xargs)
                        tenant_check=$(echo "$tenant_check" | xargs)
                        
                        if [ -n "$code" ]; then
                            # Get password from radcheck in TENANT schema (FARMERS)
                            # MULTI-TENANCY: Each tenant's RADIUS credentials are in their own schema
                            if [ -n "$username" ]; then
                                PASSWORD_QUERY="SELECT value FROM radcheck WHERE username = '$username' AND attribute = 'Cleartext-Password' LIMIT 1;"
                                password=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; $PASSWORD_QUERY" | grep -v "^$" | grep -v "SET" | head -1 | xargs)
                                if [ -z "$password" ]; then
                                    password="No Password"
                                fi
                            else
                                username="No User"
                                password="No Password"
                            fi
                            
                            # Color code the check result
                            if [ "$tenant_check" = "OK" ]; then
                                CHECK_COLOR="${GREEN}"
                            else
                                CHECK_COLOR="${RED}"
                            fi
                            
                            printf "${WHITE}%-15s${NC} | ${BLUE}%-20s${NC} | ${GREEN}%-13s${NC} | ${YELLOW}%-13s${NC} | ${CHECK_COLOR}%-12s${NC}\n" \
                                "$code" "$name" "$username" "$password" "$tenant_check"
                        fi
                    done
                    echo ""
                fi
                
                # Get employees for this tenant
                EMPLOYEES_QUERY="
                SELECT 
                    e.employee_number as code,
                    SUBSTRING(e.first_name || ' ' || e.last_name, 1, 20) as name,
                    u.username as username,
                    e.tenant_id as db_tenant_id,
                    CASE WHEN e.tenant_id = '$tenant_id' THEN 'OK' ELSE 'LEAK!' END as tenant_check
                FROM employees e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.deleted_at IS NULL
                ORDER BY e.employee_number;"
                
                EMPLOYEE_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL;" | grep -E '^[[:space:]]*[0-9]+[[:space:]]*$' | xargs)
                
                if [ -n "$EMPLOYEE_COUNT" ] && [ "$EMPLOYEE_COUNT" -gt 0 ] 2>/dev/null; then
                    echo -e "${GREEN}👥 EMPLOYEES (${EMPLOYEE_COUNT}):${NC}"
                    echo -e "${CYAN}Number          | Name                 | Username      | Password      | Tenant Check${NC}"
                    echo -e "${CYAN}----------------+----------------------+---------------+---------------+--------------${NC}"
                    
                    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; $EMPLOYEES_QUERY" | tail -n +2 | while IFS='|' read -r code name username db_tenant_id tenant_check; do
                        code=$(echo "$code" | xargs)
                        name=$(echo "$name" | xargs)
                        username=$(echo "$username" | xargs)
                        db_tenant_id=$(echo "$db_tenant_id" | xargs)
                        tenant_check=$(echo "$tenant_check" | xargs)
                        
                        if [ -n "$code" ]; then
                            # Get password from radcheck in TENANT schema (EMPLOYEES)
                            # MULTI-TENANCY: Each tenant's RADIUS credentials are in their own schema
                            if [ -n "$username" ]; then
                                PASSWORD_QUERY="SELECT value FROM radcheck WHERE username = '$username' AND attribute = 'Cleartext-Password' LIMIT 1;"
                                password=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SET search_path TO \"$schema_name\", public; $PASSWORD_QUERY" | grep -v "^$" | grep -v "SET" | head -1 | xargs)
                                if [ -z "$password" ]; then
                                    password="No Password"
                                fi
                            else
                                username="No User"
                                password="No Password"
                            fi
                            
                            # Color code the check result
                            if [ "$tenant_check" = "OK" ]; then
                                CHECK_COLOR="${GREEN}"
                            else
                                CHECK_COLOR="${RED}"
                            fi
                            
                            printf "${WHITE}%-15s${NC} | ${BLUE}%-20s${NC} | ${GREEN}%-13s${NC} | ${YELLOW}%-13s${NC} | ${CHECK_COLOR}%-12s${NC}\n" \
                                "$code" "$name" "$username" "$password" "$tenant_check"
                        fi
                    done
                    echo ""
                fi
                
                echo ""
            fi
        done
    fi
else
    # Simple view - Show ALL users (from users table + radcheck) without passwords
    QUERY="
    SELECT 
        COALESCE(rc.id::text, u.id::text) as id,
        COALESCE(rc.username, u.email) as username,
        '********' as password,
        COALESCE(m.schema_name, 'public') as schema,
        COALESCE(r.name, m.user_role, 'system_admin') as role,
        COALESCE(m.is_active::text, u.is_active::text, 'true') as active,
        CASE 
            WHEN rc.username IS NULL THEN 'No RADIUS'
            WHEN m.username IS NULL THEN 'System Admin'
            ELSE 'Tenant User'
        END as user_type,
        CASE WHEN rc.username IS NOT NULL THEN 'Yes' ELSE 'No' END as has_radius
    FROM users u
    FULL OUTER JOIN radcheck rc ON u.email = rc.username AND rc.attribute = 'Cleartext-Password'
    LEFT JOIN radius_user_schema_mapping m ON COALESCE(rc.username, u.email) = m.username
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.email IS NOT NULL OR rc.username IS NOT NULL
    ORDER BY 
        CASE WHEN rc.username IS NULL THEN 2 WHEN m.username IS NULL THEN 0 ELSE 1 END,
        COALESCE(rc.username, u.email);"
    
    echo -e "${CYAN}Username              | Password     | Schema        | Role          | Active | Type         | RADIUS${NC}"
    echo -e "${CYAN}----------------------+--------------+---------------+---------------+--------+--------------+--------${NC}"
    
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$QUERY" | while IFS='|' read -r id username password schema role active user_type has_radius; do
        # Trim whitespace
        id=$(echo "$id" | xargs)
        username=$(echo "$username" | xargs)
        password=$(echo "$password" | xargs)
        schema=$(echo "$schema" | xargs)
        role=$(echo "$role" | xargs)
        active=$(echo "$active" | xargs)
        user_type=$(echo "$user_type" | xargs)
        has_radius=$(echo "$has_radius" | xargs)
        
        if [ -n "$username" ]; then
            # Color code based on user type
            if [ "$user_type" = "System Admin" ]; then
                TYPE_COLOR="${MAGENTA}"
            elif [ "$user_type" = "No RADIUS" ]; then
                TYPE_COLOR="${RED}"
            else
                TYPE_COLOR="${BLUE}"
            fi
            
            # Color code RADIUS status
            if [ "$has_radius" = "Yes" ]; then
                RADIUS_COLOR="${GREEN}"
            else
                RADIUS_COLOR="${RED}"
            fi
            
            printf "${GREEN}%-21s${NC} | ${YELLOW}%-12s${NC} | ${CYAN}%-13s${NC} | ${BLUE}%-13s${NC} | ${WHITE}%-6s${NC} | ${TYPE_COLOR}%-12s${NC} | ${RADIUS_COLOR}%-6s${NC}\n" \
                "$username" "$password" "$schema" "$role" "$active" "$user_type" "$has_radius"
        fi
    done
fi

echo ""

# Show statistics - Count ALL users
COUNT_QUERY="
SELECT 
    COUNT(*) FILTER (WHERE rc.username IS NOT NULL AND m.username IS NULL) as system_admins,
    COUNT(*) FILTER (WHERE rc.username IS NOT NULL AND m.username IS NOT NULL) as tenant_users_with_radius,
    COUNT(*) FILTER (WHERE rc.username IS NULL) as users_without_radius,
    COUNT(*) FILTER (WHERE rc.username IS NOT NULL) as total_radius_users,
    COUNT(*) as total_users
FROM users u
FULL OUTER JOIN radcheck rc ON u.email = rc.username AND rc.attribute = 'Cleartext-Password'
LEFT JOIN radius_user_schema_mapping m ON COALESCE(rc.username, u.email) = m.username
WHERE u.email IS NOT NULL OR rc.username IS NOT NULL;"

STATS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "$COUNT_QUERY")
SYSTEM_ADMINS=$(echo "$STATS" | awk -F'|' '{print $1}' | xargs)
TENANT_USERS=$(echo "$STATS" | awk -F'|' '{print $2}' | xargs)
NO_RADIUS=$(echo "$STATS" | awk -F'|' '{print $3}' | xargs)
RADIUS_USERS=$(echo "$STATS" | awk -F'|' '{print $4}' | xargs)
TOTAL=$(echo "$STATS" | awk -F'|' '{print $5}' | xargs)

echo -e "${CYAN}📊 Statistics:${NC}"
echo -e "${WHITE}   System Admins:           ${MAGENTA}$SYSTEM_ADMINS${NC}"
echo -e "${WHITE}   Tenant Users (RADIUS):   ${BLUE}$TENANT_USERS${NC}"
echo -e "${WHITE}   Users Without RADIUS:    ${RED}$NO_RADIUS${NC}"
echo -e "${WHITE}   Total RADIUS Users:      ${GREEN}$RADIUS_USERS${NC}"
echo -e "${WHITE}   Total Users:             ${GREEN}$TOTAL${NC}"

echo ""

# Show additional info
if [ "$DETAILED" = false ]; then
    echo -e "${CYAN}💡 Tip: Use -d flag to show passwords${NC}"
fi

echo -e "${CYAN}💡 To create a new user: ${WHITE}./create-radius-user.sh -u username -p password${NC}"
echo -e "${CYAN}💡 To delete a user: ${WHITE}./delete-radius-user.sh -u username${NC}"
echo ""
