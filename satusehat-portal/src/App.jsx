import { Routes, Route, Navigate } from 'react-router-dom';
import Login from './Login';
import Dashboard from './Dashboard';
import { useState, useEffect } from 'react';

function App() {
  const [token, setToken] = useState(localStorage.getItem('token'));

  useEffect(() => {
    const handleStorageChange = () => {
      setToken(localStorage.getItem('token'));
    };
    window.addEventListener('storage', handleStorageChange);
    return () => window.removeEventListener('storage', handleStorageChange);
  }, []);

  const setAuthToken = (newToken) => {
    if (newToken) {
      localStorage.setItem('token', newToken);
    } else {
      localStorage.removeItem('token');
    }
    setToken(newToken);
  };

  return (
    <Routes>
      <Route 
        path="/login" 
        element={!token ? <Login setToken={setAuthToken} /> : <Navigate to="/" replace />} 
      />
      <Route 
        path="/" 
        element={token ? <Dashboard token={token} setToken={setAuthToken} /> : <Navigate to="/login" replace />} 
      />
    </Routes>
  );
}

export default App;
