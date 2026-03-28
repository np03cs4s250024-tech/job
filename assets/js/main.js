/**
 * JSTACK — main.js
 * Global API client, auth guards, navbar, UI helpers
 */

const BASE = '/jobportalsystem';
const API  = BASE + '/api';

// ── Universal Fetch Wrapper ───────────────────────────────────────────────────
async function _request(method, endpoint, data = null) {
    const opts = {
        method,
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
    };
    if (data) opts.body = JSON.stringify(data);

    try {
        const res = await fetch(API + endpoint, opts);
        const ct  = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            const txt = await res.text();
            console.error('Non-JSON from server:', txt.slice(0, 500));
            return { success: false, error: 'Server error — check PHP/XAMPP logs.' };
        }
        const json = await res.json();
        return res.ok ? json : { success: false, error: json.error || 'Request failed.' };
    } catch (e) {
        console.error('Network error:', e);
        return { success: false, error: 'Network error. Is XAMPP running?' };
    }
}

// ✅ All HTTP methods exported globally
const apiGet    = ep      => _request('GET',    ep);
const apiPost   = (ep, d) => _request('POST',   ep, d);
const apiPut    = (ep, d) => _request('PUT',    ep, d);
const apiPatch  = (ep, d) => _request('PATCH',  ep, d);
const apiDelete = ep      => _request('DELETE', ep);

// ── Auth Helpers ──────────────────────────────────────────────────────────────
async function getCurrentUser() {
    const r = await apiGet('/auth.php?action=me');
    return (r && r.success) ? r.user : null;
}

async function requireLogin() {
    const u = await getCurrentUser();
    if (!u) { window.location.href = BASE + '/auth/login.html'; return null; }
    return u;
}

async function requireRole(role) {
    const u = await getCurrentUser();
    if (!u || u.role !== role) { window.location.href = BASE + '/auth/login.html'; return null; }
    return u;
}

async function logout() {
    await apiPost('/auth.php?action=logout', {});
    window.location.href = BASE + '/auth/login.html';
}

// ── XSS-safe output ───────────────────────────────────────────────────────────
function escHtml(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}

// ── UI Utilities ──────────────────────────────────────────────────────────────
function showAlert(containerId, message, type = 'success') {
    const el = document.getElementById(containerId);
    if (!el) return;
    const cfg = {
        success: ['#d4edda', '#155724', '#c3e6cb'],
        error:   ['#f8d7da', '#721c24', '#f5c6cb'],
        info:    ['#d1ecf1', '#0c5460', '#bee5eb'],
    }[type] || ['#d1ecf1','#0c5460','#bee5eb'];
    el.innerHTML = `<div style="padding:12px 16px;border-radius:6px;margin-bottom:16px;
        background:${cfg[0]};color:${cfg[1]};border:1px solid ${cfg[2]};">${message}</div>`;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearAlert(containerId) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = '';
}

function setLoading(btnId, isLoading, label = 'Processing...') {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    if (isLoading) {
        btn.dataset.orig = btn.textContent;
        btn.disabled     = true;
        btn.textContent  = label;
    } else {
        btn.disabled    = false;
        btn.textContent = btn.dataset.orig || 'Submit';
    }
}

// ── Navbar ────────────────────────────────────────────────────────────────────
async function initNavbar() {
    const nav = document.getElementById('nav-actions');
    if (!nav) return;
    const user = await getCurrentUser();
    if (user) {
        const paths = {
            admin:    BASE + '/admin/dashboard.html',
            employer: BASE + '/employer/dashboard.html',
            seeker:   BASE + '/seeker/dashboard.html',
        };
        nav.innerHTML = `
            <span style="color:white;margin-right:12px;">👤 ${escHtml(user.name)}</span>
            <a href="${paths[user.role] || BASE + '/index.html'}"
               style="color:white;margin-right:12px;">Dashboard</a>
            <a href="#" onclick="logout()" style="color:#ffd700;">Logout</a>`;
    } else {
        nav.innerHTML = `
            <a href="${BASE}/auth/login.html"    style="color:white;margin-right:12px;">Login</a>
            <a href="${BASE}/auth/register.html" style="color:white;">Register</a>`;
    }
}

// Auto-init navbar on every page load
document.addEventListener('DOMContentLoaded', initNavbar);