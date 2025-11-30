# WiFi Hotspot Management System

A comprehensive WiFi hotspot billing and management system built with Laravel, Vue.js, and MikroTik integration.

## 🚀 Features

- **Router Management** - Manage multiple MikroTik routers
- **Hotspot & PPPoE** - Support for both connection types
- **Billing System** - M-Pesa integration for payments
- **User Management** - Customer accounts and sessions
- **Package Management** - Flexible pricing plans
- **Real-time Monitoring** - Live connection tracking
- **Reports & Analytics** - Comprehensive business insights
- **WebSocket Support** - Real-time updates via Soketi

## 📁 Project Structure

```
wifi-hotspot/
├── backend/              # Laravel API backend
│   ├── app/             # Application code
│   ├── config/          # Configuration files
│   ├── database/        # Migrations & seeders
│   ├── routes/          # API routes
│   └── tests/           # Backend tests
│
├── frontend/            # Vue.js frontend
│   ├── src/
│   │   ├── assets/      # Static assets
│   │   ├── components/  # Vue components
│   │   │   ├── common/       # Shared components
│   │   │   ├── dashboard/    # Dashboard components
│   │   │   │   ├── cards/    # Stat cards
│   │   │   │   ├── charts/   # Charts
│   │   │   │   └── widgets/  # Widgets
│   │   │   ├── routers/      # Router components
│   │   │   │   └── modals/   # Router modals
│   │   │   ├── packages/     # Package components
│   │   │   └── layout/       # Layout components
│   │   │
│   │   ├── composables/ # Vue composables
│   │   │   ├── auth/         # Authentication
│   │   │   ├── data/         # Data fetching
│   │   │   ├── utils/        # Utilities
│   │   │   └── websocket/    # WebSocket logic
│   │   │
│   │   ├── views/       # Page components
│   │   │   ├── public/       # Public pages
│   │   │   ├── auth/         # Auth pages
│   │   │   ├── dashboard/    # Dashboard pages
│   │   │   └── test/         # Test pages
│   │   │
│   │   ├── router/      # Vue Router
│   │   ├── stores/      # Pinia stores
│   │   └── plugins/     # Vue plugins
│   │
│   └── tests/           # Frontend tests
│
├── docs/                # 📚 All documentation
│   ├── README.md        # Documentation index
│   └── *.md             # 77 documentation files
│
├── freeradius/          # FreeRADIUS configuration
├── nginx/               # Nginx configuration
├── postgres/            # PostgreSQL configuration
├── soketi/              # WebSocket server config
├── scripts/             # Utility scripts
├── storage/             # Application storage
└── tests/               # Integration tests
```

## 🛠️ Tech Stack

### Backend
- **Framework:** Laravel 11
- **Database:** PostgreSQL
- **Queue:** Redis
- **WebSocket:** Soketi (Laravel Echo)
- **Authentication:** Laravel Sanctum
- **API:** RESTful API

### Frontend
- **Framework:** Vue.js 3
- **Build Tool:** Vite
- **State Management:** Pinia
- **Routing:** Vue Router
- **Styling:** Tailwind CSS
- **HTTP Client:** Axios
- **WebSocket:** Laravel Echo

### Infrastructure
- **Router API:** MikroTik RouterOS API
- **RADIUS:** FreeRADIUS
- **Web Server:** Nginx
- **Containerization:** Docker & Docker Compose

## 📋 Prerequisites

- Docker & Docker Compose
- Node.js 18+ (for local development)
- PHP 8.2+ (for local development)
- Composer

## 🚀 Quick Start

### Using Docker (Recommended)

```bash
# Clone the repository
git clone <repository-url>
cd wifi-hotspot

# Start all services
docker-compose up -d

# Backend setup
docker-compose exec backend composer install
docker-compose exec backend php artisan key:generate
docker-compose exec backend php artisan migrate --seed

# Frontend setup
docker-compose exec frontend npm install
docker-compose exec frontend npm run build

# Access the application
# Frontend: http://localhost:5173
# Backend API: http://localhost:8000
```

### Local Development

#### Backend Setup
```bash
cd backend

# Install dependencies
composer install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate --seed

# Start queue workers
php artisan queue:work

# Start development server
php artisan serve
```

#### Frontend Setup
```bash
cd frontend

# Install dependencies
npm install

# Start development server
npm run dev

# Build for production
npm run build
```

## 📚 Documentation

All documentation is located in the `docs/` directory:

### 🏗️ **Architecture & Structure**
- **[Frontend Architecture](docs/FRONTEND_ARCHITECTURE.md)** - ⭐ **NEW** Modular frontend structure
- **[Frontend Structure Guide](docs/FRONTEND_STRUCTURE_GUIDE.md)** - Frontend organization (legacy)
- **[Database Schema](docs/DATABASE_SCHEMA.md)** - Database design

### 🔒 **Security**
- **[Security Audit Report](docs/SECURITY_AUDIT_REPORT.md)** - Comprehensive security review
- **[Rate Limiting & Security](docs/RATE_LIMITING_AND_SECURITY.md)** - DDoS protection & account suspension
- **[Suspension Events Broadcasting](docs/SUSPENSION_EVENTS_BROADCASTING.md)** - Real-time security alerts
- **[Queued Broadcasting](docs/QUEUED_BROADCASTING_FINAL.md)** - ⭐ **NEW** Queued event broadcasting

### 📊 **Features**
- **[Dashboard Documentation](docs/DASHBOARD_REDESIGN.md)** - Dashboard features
- **[Queue System](docs/QUEUE_SYSTEM.md)** - Background job processing
- **[WebSocket Setup](docs/WEBSOCKET_TESTING_GUIDE.md)** - Real-time updates

### 🧪 **Testing & Troubleshooting**
- **[Testing Guide](docs/TESTING_COMPLETE.md)** - Testing procedures
- **[Troubleshooting](docs/TROUBLESHOOTING_GUIDE.md)** - Common issues

### 📋 **Planning & Migration**
- **[Frontend Reorganization Plan](docs/FRONTEND_REORGANIZATION_PLAN.md)** - Migration guide

## 🔧 Configuration

### Environment Variables

#### Backend (.env)
```env
APP_NAME="WiFi Hotspot"
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=wifi_hotspot
DB_USERNAME=postgres
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PORT=6379

BROADCAST_DRIVER=pusher
QUEUE_CONNECTION=redis

PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_HOST=soketi
PUSHER_PORT=6001
```

#### Frontend (.env)
```env
VITE_API_URL=http://localhost:8000
VITE_WS_HOST=localhost
VITE_WS_PORT=6001
VITE_WS_KEY=your-app-key
```

## 🧪 Testing

### Backend Tests
```bash
cd backend
php artisan test
```

### Frontend Tests
```bash
cd frontend
npm run test
```

### E2E Tests
```bash
cd frontend
npm run test:e2e
```

## 📊 Key Features

### Dashboard
- Real-time statistics
- Financial metrics (daily, weekly, monthly, yearly)
- Customer retention rate
- SMS balance monitoring
- Interactive charts
- System health monitoring

### Router Management
- Add/edit/delete routers
- Live status monitoring
- Provisioning automation
- Backup configurations
- Performance metrics

### User Management
- Customer accounts
- Session tracking
- Package assignments
- Payment history
- Usage reports

### Billing
- M-Pesa integration
- Multiple payment methods
- Invoice generation
- Payment tracking
- Revenue reports

## 🔐 Security

- Authentication via Laravel Sanctum
- Role-based access control (RBAC)
- API rate limiting
- CSRF protection
- XSS protection
- SQL injection prevention

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 License

This project is proprietary software. All rights reserved.

## 👥 Support

For support and questions:
- Check the [documentation](docs/README.md)
- Review [troubleshooting guide](docs/TROUBLESHOOTING_GUIDE.md)
- Open an issue on GitHub

## 🎯 Roadmap

- [ ] Mobile app (React Native)
- [ ] Advanced analytics dashboard
- [x] Multi-tenancy support ✅
- [ ] API rate limiting per user
- [ ] Automated backup system
- [ ] SMS notification system
- [ ] Email notification system

## ✨ Recent Updates

- ✅ **Frontend Modular Architecture** - ⭐ **NEW** Organized into common, system-admin, and tenant modules
- ✅ **Queued Broadcasting** - ⭐ **NEW** All events now queued via Supervisor
- ✅ **Security Enhancements** - Rate limiting, DDoS protection, account suspension
- ✅ **Multi-tenancy support** - Full tenant isolation
- ✅ **System Admin UI Fix** - Sidebar and topbar now visible
- ✅ Dashboard redesign with grouped sections
- ✅ Financial metrics tracking
- ✅ Customer retention analytics
- ✅ Real-time WebSocket updates
- ✅ Comprehensive documentation

## 📈 Status

- **Frontend:** ✅ Production Ready (Restructured v2.0)
- **Backend:** ✅ Production Ready
- **Security:** 🔒 Hardened (Rate limiting, DDoS protection)
- **Broadcasting:** 🟢 Queued & Optimized
- **Documentation:** ✅ Complete & Organized
- **Testing:** ✅ Passing
- **Build:** ✅ Successful

---

**Version:** 2.0.0  
**Last Updated:** 2025-10-28  
**Status:** Production Ready 🚀
