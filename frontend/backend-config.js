/* PHP + MySQL backend connection. Default assumes this repo is
   deployed as-is (frontend/ and backend/ as siblings under the same
   domain), so '../backend/api' resolves correctly from any page in
   frontend/ without extra web server config. Override if you deploy
   the API elsewhere, e.g. 'https://api.yourdomain.com'. */
window.API_BASE_URL = '../backend/api';
