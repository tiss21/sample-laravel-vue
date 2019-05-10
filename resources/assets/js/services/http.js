import axios from 'axios'

export default {
  request (method, url, data, successCallback = null, errorCallback = null) {
    axios
      .request({
        url,
        data,
        method: method.toLowerCase()
      })
      .then(successCallback)
      .catch(errorCallback)
  },

  get (url, successCallback = null, errorCallback = null) {
    return this.request('get', url, {}, successCallback, errorCallback)
  },

  post (url, data, successCallback = null, errorCallback = null) {
    return this.request('post', url, data, successCallback, errorCallback)
  },

  put (url, data, successCallback = null, errorCallback = null) {
    return this.request('put', url, data, successCallback, errorCallback)
  },

  delete (url, data = {}, successCallback = null, errorCallback = null) {
    return this.request('delete', url, data, successCallback, errorCallback)
  },

  /**
   * Init the service.
   */
  init () {
    axios.defaults.baseURL = '/api'

    // Intercept the request to make sure the token is injected into the header.
    axios.interceptors.request.use(config => {
      config.headers['X-CSRF-TOKEN']     = document.getElementsByName('csrf-token')[0].content
      config.headers['X-Requested-With'] = 'XMLHttpRequest'
      return config
    })
  }
}
