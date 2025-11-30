# Testing Scripts Guide

This directory contains automated and manual testing scripts for the User Management Restructure.

---

## 📁 Available Scripts

### Bash Scripts (Linux/Mac/Git Bash/WSL)

#### `start-dev.sh`
Starts the development server.

```bash
./tests/start-dev.sh
```

**What it does:**
- Checks if frontend directory exists
- Installs dependencies if needed
- Starts Vite dev server on port 3000

---

#### `quick-test.sh`
Quick route verification test (requires dev server running).

```bash
./tests/quick-test.sh
```

**What it does:**
- Checks if dev server is running
- Tests all user management routes
- Reports which routes are accessible
- Shows quick links for manual testing

---

#### `run-all-tests.sh`
Comprehensive automated test suite.

```bash
./tests/run-all-tests.sh
```

**What it does:**
- Checks prerequisites (Node.js, npm, curl)
- Verifies dev server is running
- Tests all routes (Admin, PPPoE, Hotspot)
- Tests API endpoints (if backend running)
- Verifies file structure
- Checks all components exist
- Provides detailed summary

---

#### `check-components.sh`
Verifies all components and files exist.

```bash
./tests/check-components.sh
```

**What it does:**
- Checks all base components
- Checks layout templates
- Checks user management components
- Checks composables
- Checks documentation files
- Reports missing files

---

### PowerShell Scripts (Windows)

#### `quick-test.ps1`
Quick route verification test for Windows.

```powershell
.\tests\quick-test.ps1
```

Same functionality as `quick-test.sh` but for Windows PowerShell.

---

## 🚀 Quick Start

### Option 1: Full Automated Test (Recommended)

```bash
# Start dev server (in one terminal)
./tests/start-dev.sh

# Run all tests (in another terminal)
./tests/run-all-tests.sh
```

### Option 2: Quick Manual Test

```bash
# Start dev server
./tests/start-dev.sh

# Run quick test
./tests/quick-test.sh

# Then manually test in browser
```

### Option 3: Component Verification Only

```bash
# Just check if all files exist
./tests/check-components.sh
```

---

## 📋 Manual Testing

For detailed manual testing, see:
- **`MANUAL_TEST_GUIDE.md`** - Comprehensive testing checklist
- **`../IMMEDIATE_TESTING_STEPS.md`** - Quick 5-minute test guide

---

## 🔧 Troubleshooting

### Script Permission Denied

```bash
chmod +x tests/*.sh
```

### Dev Server Not Running

```bash
cd frontend
npm run dev
```

### curl Command Not Found

Install curl:
- **Ubuntu/Debian:** `sudo apt-get install curl`
- **Mac:** `brew install curl`
- **Windows:** Use Git Bash or WSL

### Tests Failing

1. Check dev server is running: `http://localhost:3000`
2. Check browser console for errors
3. Clear cache and restart: `rm -rf frontend/node_modules/.vite && npm run dev`

---

## 📊 Test Coverage

### Automated Tests Cover:
- ✅ Route accessibility (8 routes)
- ✅ File structure (25+ files)
- ✅ Component existence (12 base + 4 templates)
- ✅ Composables (3 files)
- ✅ Documentation (5 files)

### Manual Tests Required:
- User interactions (clicks, forms)
- Visual verification (colors, layouts)
- Data filtering and search
- Modal functionality
- API integration

---

## 🎯 Success Criteria

**Automated tests pass if:**
- All routes return 200 or 302
- All files exist
- Dev server is running

**Manual tests pass if:**
- All three user views load correctly
- Navigation works
- Filters and search work
- Visual distinctions are clear
- No console errors

---

## 📝 Test Results

After running tests, document results in:
- `MANUAL_TEST_GUIDE.md` (checkboxes)
- Create `TEST_RESULTS.md` with findings

---

## 🔄 CI/CD Integration

These scripts can be integrated into CI/CD pipelines:

```yaml
# Example GitHub Actions
- name: Run Tests
  run: |
    npm install
    npm run dev &
    sleep 5
    ./tests/run-all-tests.sh
```

---

## 📞 Support

If tests fail or you need help:
1. Check the error messages
2. Review `IMMEDIATE_TESTING_STEPS.md`
3. Check browser console
4. Verify dev server logs

---

**Ready to test?** Start with `./tests/start-dev.sh`! 🚀
