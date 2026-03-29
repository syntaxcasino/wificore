#!/bin/bash

# Script to create all remaining Vue components
# Run this from the project root directory

COMPONENTS_DIR="frontend/src/modules/tenant/components"
VIEWS_DIR="frontend/src/modules/tenant/views"

echo "🚀 Creating all remaining Vue components..."
echo ""

# Create Positions components
echo "📦 Creating Positions components..."
cp "$VIEWS_DIR/DepartmentsView.vue" "$VIEWS_DIR/PositionsView.vue"
cp "$COMPONENTS_DIR/DepartmentCard.vue" "$COMPONENTS_DIR/PositionCard.vue"
cp "$COMPONENTS_DIR/DepartmentForm.vue" "$COMPONENTS_DIR/PositionForm.vue"

# Create Employees components
echo "📦 Creating Employees components..."
cp "$VIEWS_DIR/DepartmentsView.vue" "$VIEWS_DIR/EmployeesView.vue"
cp "$COMPONENTS_DIR/DepartmentCard.vue" "$COMPONENTS_DIR/EmployeeCard.vue"
cp "$COMPONENTS_DIR/DepartmentForm.vue" "$COMPONENTS_DIR/EmployeeForm.vue"

# Create Expenses components
echo "📦 Creating Expenses components..."
cp "$VIEWS_DIR/DepartmentsView.vue" "$VIEWS_DIR/ExpensesView.vue"
cp "$COMPONENTS_DIR/DepartmentCard.vue" "$COMPONENTS_DIR/ExpenseCard.vue"
cp "$COMPONENTS_DIR/DepartmentForm.vue" "$COMPONENTS_DIR/ExpenseForm.vue"

# Create Revenues components
echo "📦 Creating Revenues components..."
cp "$VIEWS_DIR/DepartmentsView.vue" "$VIEWS_DIR/RevenuesView.vue"
cp "$COMPONENTS_DIR/DepartmentCard.vue" "$COMPONENTS_DIR/RevenueCard.vue"
cp "$COMPONENTS_DIR/DepartmentForm.vue" "$COMPONENTS_DIR/RevenueForm.vue"

echo ""
echo "✅ All component files created!"
echo ""
echo "⚠️  IMPORTANT: Now you need to update each file:"
echo "1. Replace 'department' with the correct entity name"
echo "2. Update icon (Building2 → Briefcase/Users/DollarSign/TrendingUp)"
echo "3. Update color (purple → blue/green/red/emerald)"
echo "4. Update form fields"
echo ""
echo "📚 See QUICK_COMPONENT_COMPLETION_GUIDE.md for details"
