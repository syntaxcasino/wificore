/**
 * Lazy Image Loading Composable
 * Provides lazy loading for images with WebP support and fallbacks
 */
import { ref, onMounted, onUnmounted } from 'vue'

export function useLazyImage(options = {}) {
  const {
    rootMargin = '50px',
    threshold = 0.01,
    placeholder = '/assets/images/placeholder.svg'
  } = options

  const imageRef = ref(null)
  const isLoaded = ref(false)
  const isInViewport = ref(false)
  const error = ref(null)
  const currentSrc = ref(placeholder)

  let observer = null

  const loadImage = async (src) => {
    if (!src) return

    try {
      // Try WebP first, fallback to original
      const webpSrc = src.replace(/\.(jpe?g|png)$/i, '.webp')
      
      // Check WebP support
      const supportsWebP = document.createElement('canvas')
        .toDataURL('image/webp')
        .indexOf('data:image/webp') === 0

      const img = new Image()
      
      await new Promise((resolve, reject) => {
        img.onload = resolve
        img.onerror = reject
        // Try WebP first if supported, otherwise use original
        img.src = supportsWebP ? webpSrc : src
      })

      currentSrc.value = supportsWebP ? webpSrc : src
      isLoaded.value = true
      error.value = null
    } catch (err) {
      // WebP failed, try original format
      try {
        const img = new Image()
        await new Promise((resolve, reject) => {
          img.onload = resolve
          img.onerror = reject
          img.src = src
        })
        currentSrc.value = src
        isLoaded.value = true
        error.value = null
      } catch (fallbackErr) {
        error.value = fallbackErr
        currentSrc.value = placeholder
      }
    }
  }

  const setupObserver = () => {
    if (!imageRef.value) return

    observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          isInViewport.value = true
          // Load the image
          const src = imageRef.value.dataset.src
          if (src) {
            loadImage(src)
          }
          // Stop observing after load
          observer.unobserve(entry.target)
        }
      })
    }, {
      rootMargin,
      threshold
    })

    observer.observe(imageRef.value)
  }

  onMounted(() => {
    // Check if IntersectionObserver is supported
    if ('IntersectionObserver' in window) {
      setupObserver()
    } else {
      // Fallback: load immediately
      isInViewport.value = true
      const src = imageRef.value?.dataset.src
      if (src) loadImage(src)
    }
  })

  onUnmounted(() => {
    if (observer) {
      observer.disconnect()
    }
  })

  return {
    imageRef,
    isLoaded,
    isInViewport,
    error,
    currentSrc,
    loadImage
  }
}

/**
 * Directive for lazy loading images
 * Usage: v-lazy="'/path/to/image.jpg'"
 */
export const vLazy = {
  mounted(el, binding) {
    const src = binding.value
    if (!src) return

    // Set data-src for lazy loading
    el.dataset.src = src
    
    // Set placeholder or low-res preview
    if (!el.src) {
      el.src = '/assets/images/placeholder.svg'
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target
          const imgSrc = img.dataset.src
          
          if (imgSrc) {
            // Try WebP first
            const webpSrc = imgSrc.replace(/\.(jpe?g|png)$/i, '.webp')
            const supportsWebP = document.createElement('canvas')
              .toDataURL('image/webp')
              .indexOf('data:image/webp') === 0
            
            const imgLoader = new Image()
            imgLoader.onload = () => {
              img.src = supportsWebP ? webpSrc : imgSrc
              img.classList.add('lazy-loaded')
            }
            imgLoader.onerror = () => {
              // Fallback to original
              img.src = imgSrc
            }
            imgLoader.src = supportsWebP ? webpSrc : imgSrc
          }
          
          observer.unobserve(img)
        }
      })
    }, {
      rootMargin: '50px',
      threshold: 0.01
    })

    observer.observe(el)
    
    // Store observer for cleanup
    el._lazyObserver = observer
  },
  
  unmounted(el) {
    if (el._lazyObserver) {
      el._lazyObserver.disconnect()
    }
  }
}
