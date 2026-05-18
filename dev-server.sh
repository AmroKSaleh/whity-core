#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}🚀 Starting Whity Core Development Servers${NC}"
echo -e "${YELLOW}Backend (FrankenPHP + PostgreSQL):${NC} http://localhost:8000"
echo -e "${YELLOW}Frontend (Next.js):${NC} http://localhost:3000"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop all servers${NC}"
echo ""

# Function to cleanup on exit
cleanup() {
    echo ""
    echo -e "${YELLOW}Stopping servers...${NC}"
    kill $FRONTEND_PID 2>/dev/null
    docker-compose down 2>/dev/null
    wait $FRONTEND_PID 2>/dev/null
    echo -e "${GREEN}✓ All servers stopped${NC}"
    exit 0
}

# Set trap to cleanup on Ctrl+C
trap cleanup SIGINT SIGTERM

# Start Docker services (FrankenPHP + PostgreSQL)
echo -e "${GREEN}Starting backend services...${NC}"
docker-compose up &
DOCKER_PID=$!

# Wait a moment for backend to be ready
echo -e "${YELLOW}Waiting for backend to be ready...${NC}"
sleep 5

# Start Next.js frontend
echo -e "${GREEN}Starting frontend...${NC}"
cd web && npm run dev &
FRONTEND_PID=$!

# Wait for both processes
wait

