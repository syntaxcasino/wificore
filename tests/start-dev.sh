#!/bin/bash

# Start Development Server Script

echo "=================================="
echo "Starting Development Server"
echo "=================================="
echo ""

# Check if we're in the right directory
if [ ! -d "frontend" ]; then
    echo "❌ Error: frontend directory not found"
    echo "Please run this script from the project root directory"
    exit 1
fi

# Navigate to frontend directory
cd frontend

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo "⚠️  node_modules not found. Installing dependencies..."
    npm install
    echo ""
fi

# Start dev server
echo "🚀 Starting Vite dev server..."
echo ""
echo "Once started, the app will be available at:"
echo "  http://localhost:3000"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""
echo "=================================="
echo ""

npm run dev
