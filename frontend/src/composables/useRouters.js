import { ref, reactive, watch } from 'vue';
import axios from 'axios';
import { stringify } from 'uuid';

export function useRouters() {
  const routers = ref([]);
  const loading = ref(false);
  const listError = ref('');
  const formError = ref('');
  const showFormOverlay = ref(false);
  const showDetailsOverlay = ref(false);
  const showUpdateOverlay = ref(false);
  const currentRouter = ref(null);
  const isEditing = ref(false);
  const selectedRouter = ref(null);
  const formData = ref({
    name: '',
    id: null,
    ip_address: '',
    config_token: '',
    interface_assignments: [],
    interface_services: {},
    configurations: {},
    connectivity_script: '',
    service_script: '',
    model: '',
    os_version: '',
    last_seen: null,
    status: 'pending'
  });
  const formSubmitting = ref(false);
  const currentStep = ref(1);
  const steps = ['Router Name', 'Connectivity', 'Services'];
  const configLoading = ref(false);
  const connectivityVerified = ref(false);
  const availableInterfaces = ref([]);
  const configurationProgress = reactive({ status: 'Idle', percentage: 0, message: '' });
  const formMessage = ref({ text: '', type: '' });
  const formSubmitted = ref(false);

  // Watch formData for changes
  watch(formData, (newValue) => {
    console.log('formData changed in useRouters:', JSON.parse(JSON.stringify(newValue)));
  }, { deep: true });

  const formatTimestamp = (timestamp) => {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    return date.toLocaleString();
  };

  const statusBadgeClass = (status) => {
    return {
      'px-2 py-1 text-xs font-medium rounded-full': true,
      'bg-green-100 text-green-800': status === 'active',
      'bg-yellow-100 text-yellow-800': status === 'pending',
      'bg-red-100 text-red-800': status === 'disconnected'
    };
  };

  const fetchRouters = async () => {
    loading.value = true;
    listError.value = '';
    try {
      console.log('Sending GET to /api/routers');
      const response = await axios.get('/api/routers');
      routers.value = response.data;
      console.log('fetchRouters response:', response.data);
    } catch (err) {
      listError.value = err.response?.data?.error || 'Failed to fetch routers';
      console.error('fetchRouters error:', err.message, err.response?.data);
    } finally {
      loading.value = false;
    }
  };

  const addRouter = async () => {
    console.log('addRouter called, formData:', JSON.parse(JSON.stringify(formData.value)));
    if (!formData.value.name || formData.value.name.trim() === '') {
      formMessage.value = { text: 'Router name is required', type: 'error' };
      console.error('addRouter failed: Router name is required');
      return;
    }
    formSubmitting.value = true;
    formMessage.value = { text: '', type: '' };
    try {
      console.log('Sending POST to /api/routers with name:', formData.value.name);
      const response = await axios.post('/api/routers', {
        name: formData.value.name
      });
      console.log('addRouter response:', response.data);
      formData.value = {
        ...formData.value,
        ...response.data,
        interface_assignments: response.data.interface_assignments || [],
        interface_services: response.data.interface_services || {},
        configurations: response.data.configurations || {},
        connectivity_script: response.data.connectivity_script || '',
        service_script: response.data.service_script || '',
        model: response.data.model || '',
        os_version: response.data.os_version || '',
        last_seen: response.data.last_seen || null,
        status: response.data.status || 'pending'
      };
      formMessage.value = { text: 'Router created successfully', type: 'success' };
      currentStep.value = 2;
      console.log('Updated formData after addRouter:', JSON.parse(JSON.stringify(formData.value)));
    } catch (err) {
      formMessage.value = { text: err.response?.data?.error || 'Failed to create router', type: 'error' };
      console.error('addRouter error:', err.message, err.response?.data);
    } finally {
      formSubmitting.value = false;
    }
  };

  const verifyConnectivity = async () => {
    console.log('verifyConnectivity called, formData:', JSON.parse(JSON.stringify(formData.value)));
    if (!formData.value.id) {
      console.error('verifyConnectivity failed: No router ID');
      formMessage.value = { text: 'Router ID is missing. Please create the router first by completing Step 1.', type: 'error' };
      return;
    }
    configLoading.value = true;
    formMessage.value = { text: '', type: '' };
    try {
      console.log('Sending GET to /api/routers/' + formData.value.id + '/verify-connectivity');
      const response = await axios.get(`/api/routers/${formData.value.id}/verify-connectivity`);
      console.log('verifyConnectivity response:', response.data);
      if (response.data.status === 'connected') {
        connectivityVerified.value = true;
        availableInterfaces.value = response.data.interfaces || [];
        formData.value = {
          ...formData.value,
          model: response.data.model || '',
          os_version: response.data.os_version || '',
          last_seen: response.data.last_seen || null,
          status: 'active'
        };
        formMessage.value = { text: 'Connectivity verified, router details and interfaces loaded', type: 'success' };
        console.log('Updated formData:', JSON.parse(JSON.stringify(formData.value)));
      } else {
        throw new Error(response.data.error || 'Failed to verify connectivity');
      }
    } catch (err) {
      console.error('Verify connectivity error:', err.message, 'Response:', err.response?.data, 'Status:', err.response?.status);
      connectivityVerified.value = false;
      availableInterfaces.value = [];
      const errorMessage = err.response?.data?.error || err.message || `Unable to connect to the router. Ensure the connectivity script is applied and the router is online at ${formData.value.ip_address}:8728.`;
      formMessage.value = { text: errorMessage, type: 'error' };
    } finally {
      configLoading.value = false;
    }
  };

const generateConfigs = async () => {
  formSubmitting.value = true;
  formMessage.value = { text: '', type: '' };
  try {
    console.log('Here');
    console.log(JSON.stringify(formData.value)); // ✅ fixed
    console.log('Sending POST to /api/routers/' + formData.value.id + '/generate-service-config');
  

    const response = await axios.post(`/api/routers/${formData.value.id}/generate-service-config`, formData.value);

    console.log('generateConfigs response:', response.data);
    formData.value.service_script = response.data.service_script || '';
    formMessage.value = { text: 'Service configuration generated successfully', type: 'success' };
  } catch (err) {
    formMessage.value = { text: err.response?.data?.error || 'Failed to generate service configuration', type: 'error' };
    console.error('generateConfigs error:', err.message, err.response?.data);
  } finally {
    formSubmitting.value = false;
  }
};


  const applyConfigurations = async () => {
    formSubmitting.value = true;
    configurationProgress.status = 'Applying';
    configurationProgress.percentage = 0;
    configurationProgress.message = '';
    try {
      console.log('Sending POST to /api/routers/' + formData.value.id + '/apply-configs');
      const response = await axios.post(`/api/routers/${formData.value.id}/apply-configs`, {
        service_script: formData.value.service_script
      });
      console.log('applyConfigurations response:', response.data);
      configurationProgress.percentage = 100;
      configurationProgress.status = 'Applied';
      configurationProgress.message = 'Configuration applied successfully';
      formMessage.value = { text: 'Configuration applied successfully', type: 'success' };
      formSubmitted.value = true;
      setTimeout(() => {
        showFormOverlay.value = false;
        formSubmitted.value = false;
        currentStep.value = 1;
        formData.value = {
          name: '',
          id: null,
          ip_address: '',
          config_token: '',
          interface_assignments: [],
          interface_services: {},
          configurations: {},
          connectivity_script: '',
          service_script: '',
          model: '',
          os_version: '',
          last_seen: null,
          status: 'pending'
        };
        console.log('Form reset, formData:', JSON.parse(JSON.stringify(formData.value)));
      }, 2000);
    } catch (err) {
      configurationProgress.status = 'Failed';
      configurationProgress.percentage = 0;
      configurationProgress.message = err.response?.data?.error || 'Failed to apply configuration';
      formMessage.value = { text: configurationProgress.message, type: 'error' };
      console.error('applyConfigurations error:', err.message, err.response?.data);
    } finally {
      formSubmitting.value = false;
    }
  };

  const editRouter = (router) => {
    selectedRouter.value = router;
    formData.value = {
      ...router,
      interface_assignments: router.interface_assignments || [],
      interface_services: router.interface_services || {},
      configurations: router.configurations || {},
      connectivity_script: router.connectivity_script || '',
      service_script: router.service_script || ''
    };
    isEditing.value = true;
    showUpdateOverlay.value = true;
    console.log('editRouter called, formData:', JSON.parse(JSON.stringify(formData.value)));
  };

  const updateRouter = async () => {
    formSubmitting.value = true;
    formMessage.value = { text: '', type: '' };
    try {
      console.log('Sending PUT to /api/routers/' + selectedRouter.value.id);
      await axios.put(`/api/routers/${selectedRouter.value.id}`, {
        name: formData.value.name,
        ip_address: formData.value.ip_address,
        config_token: formData.value.config_token
      });
      formMessage.value = { text: 'Router updated successfully', type: 'success' };
      showUpdateOverlay.value = false;
    } catch (err) {
      formMessage.value = { text: err.response?.data?.error || 'Failed to update router', type: 'error' };
      console.error('updateRouter error:', err.message, err.response?.data);
    } finally {
      formSubmitting.value = false;
    }
  };

  const deleteRouter = async (id) => {
    try {
      console.log('Sending DELETE to /api/routers/' + id);
      await axios.delete(`/api/routers/${id}`);
      await fetchRouters();
    } catch (err) {
      console.error('deleteRouter error:', err.message, err.response?.data);
      throw err;
    }
  };

  const openCreateOverlay = () => {
    showFormOverlay.value = true;
    isEditing.value = false;
    currentStep.value = 1;
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      interface_assignments: [],
      interface_services: {},
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending'
    };
    console.log('openCreateOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)));
  };

  const openEditOverlay = (router) => {
    editRouter(router);
  };

  const openDetails = (router) => {
    currentRouter.value = router;
    showDetailsOverlay.value = true;
    console.log('openDetails called, currentRouter:', JSON.parse(JSON.stringify(router)));
  };

  const closeDetails = () => {
    showDetailsOverlay.value = false;
    currentRouter.value = null;
  };

  const closeFormOverlay = () => {
    showFormOverlay.value = false;
    currentStep.value = 1;
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      interface_assignments: [],
      interface_services: {},
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending'
    };
    console.log('closeFormOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)));
  };

  const closeUpdateOverlay = () => {
    showUpdateOverlay.value = false;
    selectedRouter.value = null;
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      interface_assignments: [],
      interface_services: {},
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending'
    };
    console.log('closeUpdateOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)));
  };

  const nextStep = () => {
    if (currentStep.value < steps.length) {
      currentStep.value++;
      console.log('nextStep called, currentStep:', currentStep.value);
    }
  };

  const previousStep = () => {
    if (currentStep.value > 1) {
      currentStep.value--;
      console.log('previousStep called, currentStep:', currentStep.value);
    }
  };

  const copyToClipboard = (script) => {
    navigator.clipboard.writeText(script).then(() => {
      formMessage.value = { text: 'Script copied to clipboard', type: 'success' };
      console.log('copyToClipboard successful, script:', script);
    }).catch((err) => {
      formMessage.value = { text: 'Failed to copy script', type: 'error' };
      console.error('copyToClipboard failed:', err.message);
    });
  };

  const updateInterfaceAssignments = (interfaceName, event) => {
    if (event.target.checked) {
      formData.value.interface_assignments = [...formData.value.interface_assignments, interfaceName];
      if (!formData.value.interface_services[interfaceName]) {
        formData.value.interface_services[interfaceName] = 'none';
        formData.value.configurations[interfaceName] = {};
      }
    } else {
      formData.value.interface_assignments = formData.value.interface_assignments.filter(name => name !== interfaceName);
      delete formData.value.interface_services[interfaceName];
      delete formData.value.configurations[interfaceName];
    }
    console.log('updateInterfaceAssignments called, formData:', JSON.parse(JSON.stringify(formData.value)));
  };

  const updateFormData = (data) => {
    console.log('updateFormData called in useRouters, data:', JSON.parse(JSON.stringify(data)));
    Object.assign(formData.value, data);
    console.log('formData updated in useRouters:', JSON.parse(JSON.stringify(formData.value)));
  };

  return {
    routers,
    loading,
    listError,
    formError,
    showFormOverlay,
    showDetailsOverlay,
    showUpdateOverlay,
    currentRouter,
    isEditing,
    selectedRouter,
    formData,
    formSubmitting,
    currentStep,
    steps,
    configLoading,
    connectivityVerified,
    availableInterfaces,
    configurationProgress,
    formMessage,
    formSubmitted,
    fetchRouters,
    verifyConnectivity,
    addRouter,
    editRouter,
    updateRouter,
    deleteRouter,
    generateConfigs,
    applyConfigurations,
    formatTimestamp,
    statusBadgeClass,
    openCreateOverlay,
    openEditOverlay,
    openDetails,
    closeDetails,
    closeFormOverlay,
    closeUpdateOverlay,
    nextStep,
    previousStep,
    copyToClipboard,
    updateInterfaceAssignments,
    updateFormData
  };
}