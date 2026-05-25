import { useState } from 'react';

// Adjust this URL to point to where the PHP API is served.
// Adjust this URL to point to where the PHP API is served via public/config.js.
// Fallback if config.js is not loaded properly.
export const API_URL = window.PORTAL_CONFIG?.API_URL || '/php-service/api_satusehat_portal.php';

export default function Login({ setToken }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    try {
      // Use dynamic URL from configuration
      const res = await fetch(API_URL + '?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
      });

      const data = await res.json();
      if (res.ok && data.success) {
        setToken(data.token);
      } else {
        setError(data.message || 'Login failed');
      }
    } catch (err) {
      setError('Connection error. Is the PHP server running?');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-container">
      <div className="glass auth-card">
        <h1>SatuSehat Portal</h1>
        <p style={{marginBottom: '2rem', color: 'var(--text-muted)'}}>Log in with your SIMRS credentials</p>
        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label>Username</label>
            <input 
              type="text" 
              className="form-control"
              value={username} 
              onChange={e => setUsername(e.target.value)} 
              required 
            />
          </div>
          <div className="form-group">
            <label>Password</label>
            <input 
              type="password" 
              className="form-control"
              value={password} 
              onChange={e => setPassword(e.target.value)} 
              required 
            />
          </div>
          {error && <div className="error-msg">{error}</div>}
          <div style={{marginTop: '2rem'}}>
            <button type="submit" className="btn" disabled={loading}>
              {loading ? 'Logging in...' : 'Login'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
