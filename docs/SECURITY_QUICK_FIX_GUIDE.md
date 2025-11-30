# Security Quick Fix Guide - Production Deployment

**⏱️ Total Time Required:** 8 minutes  
**🎯 Goal:** Achieve 90%+ security score  
**📋 Status:** 3 critical fixes needed

---

## 🚨 Critical Fixes (Do Now!)

### **Fix #1: Disable FTP Service** ⏱️ 1 minute

**Why:** FTP is unencrypted and a major security risk

**How:**
```routeros
/ip service set ftp disabled=yes
```

**Verify:**
```routeros
/ip service print
# Look for: ftp ... disabled=yes
```

**Impact:** +10 security points (75% → 85%)

---

### **Fix #2: Configure Walled Garden** ⏱️ 2 minutes

**Why:** Users need access to captive portal and DNS

**How:**
```routeros
# Allow captive portal
/ip hotspot walled-garden add dst-host="hotspot.traidnet.co.ke" \
    action=allow comment="Captive Portal"

# Allow DNS servers
/ip hotspot walled-garden ip add dst-address=8.8.8.8 \
    action=allow comment="Google DNS"
    
/ip hotspot walled-garden ip add dst-address=1.1.1.1 \
    action=allow comment="Cloudflare DNS"

# Allow essential CDNs
/ip hotspot walled-garden add dst-host="*.googleapis.com" \
    action=allow comment="Google APIs"
    
/ip hotspot walled-garden add dst-host="*.gstatic.com" \
    action=allow comment="Google Static"
```

**Verify:**
```routeros
/ip hotspot walled-garden print
# Should show 5 entries
```

**Impact:** Portal accessibility (required for operation)

---

### **Fix #3: Enable HTTPS Redirect** ⏱️ 5 minutes

**Why:** Encrypt credential transmission

**Prerequisites:**
- SSL certificate uploaded to router
- Certificate named "server.crt"

**How:**
```routeros
# If you have certificate:
/ip hotspot profile set hs-profile-2 \
    login-by=https \
    ssl-certificate=server.crt

# If no certificate (temporary):
/ip hotspot profile set hs-profile-2 \
    login-by=http-chap,https
```

**Verify:**
```routeros
/ip hotspot profile print detail
# Look for: login-by=https
```

**Impact:** +5 security points (85% → 90%)

---

## ✅ Verification Script

Run this after applying fixes:

```routeros
# Check FTP
:if ([/ip service get ftp disabled] = yes) do={
    :put "✅ FTP: Disabled"
} else={
    :put "❌ FTP: Still enabled!"
}

# Check Walled Garden
:local wgCount [/ip hotspot walled-garden print count-only]
:if ($wgCount > 0) do={
    :put "✅ Walled Garden: $wgCount rules"
} else={
    :put "❌ Walled Garden: Not configured!"
}

# Check HTTPS
:local loginBy [/ip hotspot profile get hs-profile-2 login-by]
:if ($loginBy ~ "https") do={
    :put "✅ HTTPS: Enabled"
} else={
    :put "⚠️  HTTPS: Not enabled"
}

:put "\n=== SECURITY STATUS ==="
:put "Run full audit to get updated score"
```

---

## 🎯 Expected Results

### **Before Fixes:**
- Security Score: 75%
- FTP: ❌ Enabled
- Walled Garden: ❌ Not configured
- HTTPS: ❌ Not enabled

### **After Fixes:**
- Security Score: 90%+
- FTP: ✅ Disabled
- Walled Garden: ✅ Configured
- HTTPS: ✅ Enabled

---

## 🚀 One-Command Fix (Copy-Paste)

```routeros
# Disable FTP
/ip service set ftp disabled=yes

# Configure Walled Garden
/ip hotspot walled-garden add dst-host="hotspot.traidnet.co.ke" action=allow comment="Captive Portal"
/ip hotspot walled-garden ip add dst-address=8.8.8.8 action=allow comment="Google DNS"
/ip hotspot walled-garden ip add dst-address=1.1.1.1 action=allow comment="Cloudflare DNS"
/ip hotspot walled-garden add dst-host="*.googleapis.com" action=allow comment="Google APIs"
/ip hotspot walled-garden add dst-host="*.gstatic.com" action=allow comment="Google Static"

# Enable HTTPS (if certificate available)
/ip hotspot profile set hs-profile-2 login-by=https ssl-certificate=server.crt

# Verify
:put "=== VERIFICATION ==="
:put ("FTP Disabled: " . [/ip service get ftp disabled])
:put ("Walled Garden Rules: " . [/ip hotspot walled-garden print count-only])
:put ("Login Method: " . [/ip hotspot profile get hs-profile-2 login-by])
```

---

## 📋 Post-Fix Checklist

- [ ] FTP disabled
- [ ] Walled Garden configured (5+ rules)
- [ ] HTTPS enabled (if certificate available)
- [ ] Test captive portal access
- [ ] Test user authentication
- [ ] Verify internet access
- [ ] Check logs for errors
- [ ] Update documentation

---

## 🆘 Troubleshooting

### **Issue: Captive Portal Not Loading**

**Check:**
```routeros
/ip hotspot walled-garden print
# Verify portal domain is listed
```

**Fix:**
```routeros
/ip hotspot walled-garden add dst-host="hotspot.traidnet.co.ke" action=allow
```

---

### **Issue: HTTPS Certificate Error**

**Check:**
```routeros
/certificate print
# Verify certificate is imported
```

**Fix:**
```routeros
# Upload certificate first
/certificate import file-name=server.crt
/certificate import file-name=server.key

# Then enable HTTPS
/ip hotspot profile set hs-profile-2 ssl-certificate=server.crt
```

---

### **Issue: Users Can't Access Internet**

**Check:**
```routeros
/ip firewall nat print
# Verify masquerade rule exists
```

**Fix:**
```routeros
/ip firewall nat add chain=srcnat action=masquerade out-interface=ether1
```

---

## 📞 Support

**If issues persist:**

1. Check logs:
   ```routeros
   /log print where topics~"hotspot"
   ```

2. Check active sessions:
   ```routeros
   /ip hotspot active print
   ```

3. Check RADIUS:
   ```routeros
   /radius monitor [find service=hotspot]
   ```

4. Contact support with:
   - Router model and version
   - Error messages from logs
   - Steps already attempted

---

## ✅ Success Criteria

**System is production-ready when:**

- ✅ Security score ≥ 90%
- ✅ FTP disabled
- ✅ Walled garden configured
- ✅ HTTPS enabled
- ✅ Users can authenticate
- ✅ Internet access working
- ✅ No errors in logs

---

**Quick Fix Guide v1.0**  
**Last Updated:** 2025-10-10  
**Estimated Time:** 8 minutes  
**Difficulty:** Easy
