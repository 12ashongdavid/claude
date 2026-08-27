// Thin fetch wrapper: attaches the stored bearer token, and throws a
// plain Error with the server's message on failure so callers can just
// try/catch and show err.message.
const Api = (() => {
    function token() {
        return localStorage.getItem('pk_token');
    }
    function setToken(t) {
        if (t) localStorage.setItem('pk_token', t);
        else localStorage.removeItem('pk_token');
    }
    function setUser(u) {
        if (u) localStorage.setItem('pk_user', JSON.stringify(u));
        else localStorage.removeItem('pk_user');
    }
    function user() {
        try { return JSON.parse(localStorage.getItem('pk_user') || 'null'); }
        catch (e) { return null; }
    }
    function isLoggedIn() {
        return !!token();
    }

    async function request(path, options = {}) {
        const headers = options.headers || {};
        if (token()) headers['Authorization'] = 'Bearer ' + token();
        let res;
        try {
            res = await fetch(`${API_BASE_URL}/${path}`, { ...options, headers });
        } catch (e) {
            throw new Error('Network error. Check your connection and try again.');
        }
        let data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error('Unexpected response from the server.');
        }
        if (res.status === 401) {
            setToken(null);
            setUser(null);
        }
        if (!res.ok || data.error) {
            throw new Error(data.error || 'Something went wrong. Please try again.');
        }
        return data;
    }

    function get(path) {
        return request(path, { method: 'GET' });
    }

    function post(path, formData) {
        const body = formData instanceof FormData ? formData : new URLSearchParams(formData);
        return request(path, { method: 'POST', body });
    }

    return { token, setToken, setUser, user, isLoggedIn, get, post };
})();
