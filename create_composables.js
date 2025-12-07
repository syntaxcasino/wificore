const fs = require('fs');
const path = require('path');

const composables = [
  {
    name: 'usePositions',
    singular: 'position',
    plural: 'positions',
    module: 'HR Module',
    stats: {
      total: 0,
      active: 0,
      inactive: 0,
      by_level: [],
      by_department: []
    },
    filters: ['active', 'inactive'],
    searchFields: ['title', 'code', 'description'],
    extraMethods: []
  },
  {
    name: 'useEmployees',
    singular: 'employee',
    plural: 'employees',
    module: 'HR Module',
    stats: {
      total: 0,
      active: 0,
      on_leave: 0,
      suspended: 0,
      terminated: 0,
      by_type: {},
      by_department: []
    },
    filters: ['active', 'on_leave', 'suspended', 'terminated'],
    searchFields: ['first_name', 'last_name', 'employee_number', 'email'],
    extraMethods: ['terminate']
  },
  {
    name: 'useExpenses',
    singular: 'expense',
    plural: 'expenses',
    module: 'Finance Module',
    stats: {
      total_expenses: 0,
      total_amount: 0,
      by_status: {},
      by_category: [],
      by_payment_method: []
    },
    filters: ['pending', 'approved', 'rejected', 'paid'],
    searchFields: ['expense_number', 'description', 'vendor_name'],
    extraMethods: ['approve', 'reject', 'markAsPaid']
  },
  {
    name: 'useRevenues',
    singular: 'revenue',
    plural: 'revenues',
    module: 'Finance Module',
    stats: {
      total_revenues: 0,
      total_amount: 0,
      by_status: {},
      by_source: [],
      by_payment_method: []
    },
    filters: ['pending', 'confirmed', 'cancelled'],
    searchFields: ['revenue_number', 'description', 'reference_number'],
    extraMethods: ['confirm', 'cancel']
  }
];

const baseDir = path.join(__dirname, 'frontend', 'src', 'composables');

composables.forEach(comp => {
  const { name, singular, plural, module, stats, filters, searchFields, extraMethods } = comp;
  
  const capitalSingular = singular.charAt(0).toUpperCase() + singular.slice(1);
  const capitalPlural = plural.charAt(0).toUpperCase() + plural.slice(1);
  
  const filterComputed = filters.map(f => `
  const ${f}${capitalPlural} = computed(() => 
    ${plural}.value.filter(item => item.status === '${f}')
  )`).join('\n  ');
  
  const extraMethodsCode = extraMethods.map(method => {
    const methodName = method.charAt(0).toLowerCase() + method.slice(1);
    return `
  const ${methodName}${capitalSingular} = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(\`/${plural}/\${id}/${method.replace(/([A-Z])/g, '-$1').toLowerCase()}\`, data)
      
      const index = ${plural}.value.findIndex(item => item.id === id)
      if (index !== -1) {
        ${plural}.value[index] = response.data.data
      }
      
      toast.success('${capitalSingular} ${method} successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to ${method} ${singular}'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }`;
  }).join('\n');
  
  const content = `/**
 * ${capitalSingular} Management Composable - Event-Driven
 * WiFi Hotspot System - ${module}
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useToast } from '@/composables/useToast'

export function ${name}() {
  const loading = ref(false)
  const error = ref(null)
  const ${plural} = ref([])
  const stats = ref(${JSON.stringify(stats, null, 4)})
  
  const { toast } = useToast()

  // Computed filters
  ${filterComputed}

  // API Functions
  const fetch${capitalPlural} = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? \`/${plural}?\${params}\` : '/${plural}'
      const response = await axios.get(url)
      
      ${plural}.value = response.data.data || response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch ${plural}'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/${plural}/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const create${capitalSingular} = async (data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/${plural}', data)
      
      ${plural}.value.unshift(response.data.data)
      toast.success('${capitalSingular} created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create ${singular}'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const update${capitalSingular} = async (id, data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(\`/${plural}/\${id}\`, data)
      
      const index = ${plural}.value.findIndex(item => item.id === id)
      if (index !== -1) {
        ${plural}.value[index] = response.data.data
      }
      
      toast.success('${capitalSingular} updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update ${singular}'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const delete${capitalSingular} = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(\`/${plural}/\${id}\`)
      
      ${plural}.value = ${plural}.value.filter(item => item.id !== id)
      toast.success('${capitalSingular} deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete ${singular}'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }
${extraMethodsCode}

  const get${capitalSingular}ById = (id) => {
    return ${plural}.value.find(item => item.id === id)
  }

  const search${capitalPlural} = (query) => {
    const lowerQuery = query.toLowerCase()
    return ${plural}.value.filter(item => 
      ${searchFields.map(f => `item.${f}?.toLowerCase().includes(lowerQuery)`).join(' ||\n      ')}
    )
  }

  // Event handlers for WebSocket
  const handle${capitalSingular}Created = (${singular}) => {
    const exists = ${plural}.value.find(item => item.id === ${singular}.id)
    if (!exists) {
      ${plural}.value.unshift(${singular})
    }
  }

  const handle${capitalSingular}Updated = (${singular}) => {
    const index = ${plural}.value.findIndex(item => item.id === ${singular}.id)
    if (index !== -1) {
      ${plural}.value[index] = { ...${plural}.value[index], ...${singular} }
    }
  }

  const handle${capitalSingular}Deleted = (${singular}Id) => {
    ${plural}.value = ${plural}.value.filter(item => item.id !== ${singular}Id)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('${singular}-created', (event) => {
      if (event.detail?.${singular}) {
        handle${capitalSingular}Created(event.detail.${singular})
      }
    })

    window.addEventListener('${singular}-updated', (event) => {
      if (event.detail?.${singular}) {
        handle${capitalSingular}Updated(event.detail.${singular})
      }
    })

    window.addEventListener('${singular}-deleted', (event) => {
      if (event.detail?.${singular}Id) {
        handle${capitalSingular}Deleted(event.detail.${singular}Id)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('${singular}-created', handle${capitalSingular}Created)
    window.removeEventListener('${singular}-updated', handle${capitalSingular}Updated)
    window.removeEventListener('${singular}-deleted', handle${capitalSingular}Deleted)
  }

  return {
    // Reactive data
    ${plural},
    stats,
    ${filters.map(f => `${f}${capitalPlural}`).join(',\n    ')},
    loading,
    error,

    // API functions
    fetch${capitalPlural},
    fetchStatistics,
    create${capitalSingular},
    update${capitalSingular},
    delete${capitalSingular},
    ${extraMethods.map(m => `${m.charAt(0).toLowerCase() + m.slice(1)}${capitalSingular}`).join(',\n    ')},

    // Utility functions
    get${capitalSingular}ById,
    search${capitalPlural},

    // Event handlers
    handle${capitalSingular}Created,
    handle${capitalSingular}Updated,
    handle${capitalSingular}Deleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
`;
  
  const filename = path.join(baseDir, `${name}.js`);
  fs.writeFileSync(filename, content);
  console.log(`✅ Created: ${name}.js`);
});

console.log('\n🎉 All composables created successfully!');
