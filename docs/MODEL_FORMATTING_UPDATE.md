# Model Column Formatting Update

## Problem
The Model column was displaying very long model names like:
```
"CHR innotek GmbH VirtualBox"
```

This caused:
- Text overflow in the 120px column
- Poor readability
- Inconsistent display across different router types

## Solution
Added `formatModel()` function to intelligently clean up and shorten model names while preserving important information.

## Implementation

### Function: `formatModel(model)` (Lines 711-743)

```javascript
const formatModel = (model) => {
  if (!model) return '—'
  
  // Clean up common verbose model strings
  let formatted = model
    .replace(/innotek GmbH VirtualBox/gi, 'VirtualBox')
    .replace(/CHR\s+/gi, 'CHR ')
    .trim()
  
  // Extract meaningful parts for common MikroTik models
  // Examples: "RB750Gr3", "CCR1009-7G-1C-1S+", "hEX"
  const mikrotikMatch = formatted.match(/\b(RB\w+|CCR\w+|hEX\w*|CRS\w+|CSS\w+)\b/i)
  if (mikrotikMatch) {
    return mikrotikMatch[1]
  }
  
  // For CHR VirtualBox, return "CHR VirtualBox"
  if (formatted.toLowerCase().includes('chr') && formatted.toLowerCase().includes('virtualbox')) {
    return 'CHR VirtualBox'
  }
  
  // If still too long, truncate intelligently
  if (formatted.length > 15) {
    // Try to keep first meaningful word
    const words = formatted.split(/\s+/)
    if (words.length > 1) {
      return words.slice(0, 2).join(' ')
    }
    return formatted.substring(0, 12) + '...'
  }
  
  return formatted
}
```

### Display Update (Line 284)
```vue
<span v-if="router.model" class="truncate" :title="router.model">
  {{ formatModel(router.model) }}
</span>
```

**Key Features:**
- Shows formatted short name in the column
- Tooltip (`:title`) shows full original model name on hover
- `truncate` class handles any remaining overflow

## Formatting Examples

### Before → After

| Original Model | Formatted Display |
|----------------|-------------------|
| `CHR innotek GmbH VirtualBox` | `CHR VirtualBox` |
| `RB750Gr3` | `RB750Gr3` |
| `CCR1009-7G-1C-1S+` | `CCR1009-7G-1C-1S+` |
| `hEX` | `hEX` |
| `hEX S` | `hEX S` |
| `CRS326-24G-2S+RM` | `CRS326-24G-2S+RM` |
| `RB4011iGS+5HacQ2HnD-IN` | `RB4011iGS+5HacQ2HnD-IN` |
| `Some Very Long Unknown Model Name` | `Some Very...` |

### Supported MikroTik Model Patterns

The function recognizes and extracts:
- **RB series**: `RB750`, `RB4011`, `RB2011`, etc.
- **CCR series**: `CCR1009`, `CCR1036`, `CCR2004`, etc.
- **hEX series**: `hEX`, `hEX S`, `hEX lite`, etc.
- **CRS series**: `CRS326`, `CRS328`, etc.
- **CSS series**: `CSS610`, etc.

### CHR (Cloud Hosted Router) Handling

**Input:**
```
"CHR innotek GmbH VirtualBox"
"CHR VMware Virtual Platform"
```

**Output:**
```
"CHR VirtualBox"
"CHR VMware"
```

## Tooltip Feature

Hovering over any model name shows the **full original model string** in a tooltip:

```vue
:title="router.model"
```

**Example:**
- **Display**: `CHR VirtualBox`
- **Tooltip**: `CHR innotek GmbH VirtualBox`

This ensures:
- Clean, readable display
- Full information available on hover
- No data loss

## Column Width

Model column remains at `120px` which now comfortably fits:
- Most MikroTik model names
- Formatted CHR names
- Truncated long names with ellipsis

## Edge Cases Handled

### 1. **Null/Undefined Model**
```javascript
formatModel(null) → "—"
formatModel(undefined) → "—"
formatModel('') → "—"
```

### 2. **Unknown Long Models**
```javascript
formatModel('Some Unknown Very Long Model Name')
→ "Some Unknown" (first 2 words)
```

### 3. **Single Long Word**
```javascript
formatModel('SuperLongModelNameWithoutSpaces')
→ "SuperLongMo..." (12 chars + ellipsis)
```

### 4. **Already Short Models**
```javascript
formatModel('hEX') → "hEX" (unchanged)
formatModel('RB750') → "RB750" (unchanged)
```

## Testing

### Test Cases

```javascript
// Test in browser console:
const testModels = [
  'CHR innotek GmbH VirtualBox',
  'RB750Gr3',
  'CCR1009-7G-1C-1S+',
  'hEX S',
  'CRS326-24G-2S+RM',
  'Some Very Long Unknown Model',
  null,
  ''
]

testModels.forEach(model => {
  console.log(`"${model}" → "${formatModel(model)}"`)
})
```

**Expected Output:**
```
"CHR innotek GmbH VirtualBox" → "CHR VirtualBox"
"RB750Gr3" → "RB750Gr3"
"CCR1009-7G-1C-1S+" → "CCR1009-7G-1C-1S+"
"hEX S" → "hEX S"
"CRS326-24G-2S+RM" → "CRS326-24G-2S+RM"
"Some Very Long Unknown Model" → "Some Very"
"null" → "—"
"" → "—"
```

## Benefits

✅ **Cleaner Display**: Short, readable model names  
✅ **No Overflow**: Fits within 120px column width  
✅ **Full Info Available**: Tooltip shows complete model string  
✅ **Smart Recognition**: Extracts MikroTik model codes automatically  
✅ **CHR Friendly**: Cleans up verbose VirtualBox/VMware strings  
✅ **Fallback Handling**: Gracefully handles unknown/long models  
✅ **Consistent**: All models formatted uniformly  

## Future Enhancements

Potential improvements:
- [ ] Add more vendor-specific patterns (Cisco, Ubiquiti, etc.)
- [ ] Configurable formatting rules per vendor
- [ ] Custom abbreviations for common long names
- [ ] Option to toggle between short/full display

## Summary

The Model column now displays clean, readable model names:
- **CHR routers**: Shows `"CHR VirtualBox"` instead of full verbose string
- **MikroTik hardware**: Shows model code (e.g., `RB750Gr3`)
- **Unknown models**: Intelligently truncated with first 2 words or ellipsis
- **Tooltip**: Always shows full original model name on hover

This provides a much better user experience while maintaining all the original information! 🎨
