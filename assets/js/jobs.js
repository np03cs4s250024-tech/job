/**
 * JSTACK — jobs.js
 * Job search, applications, saved jobs, autocomplete, render helpers
 * All apiGet/apiPost calls use RELATIVE paths (main.js prepends /jobportalsystem/api)
 */

// ── Job Fetching & Advanced Search ───────────────────────────────────────────
async function loadAllJobs(keyword = '', category = '', location = '') {
    // ✅ FIX: use relative path — main.js apiGet prepends /jobportalsystem/api
    let url = '/jobs.php?action=search';
    if (keyword)                          url += `&keyword=${encodeURIComponent(keyword)}`;
    if (category && category !== 'All')   url += `&category=${encodeURIComponent(category)}`;
    if (location)                         url += `&location=${encodeURIComponent(location)}`;
    return apiGet(url);
}

// ── Ajax Autocomplete ─────────────────────────────────────────────────────────
function setupAutocomplete(inputId, listId) {
    const input = document.getElementById(inputId);
    const list  = document.getElementById(listId);
    if (!input || !list) return;

    input.addEventListener('input', async () => {
        const q = input.value.trim();
        list.innerHTML = '';
        if (q.length < 2) { list.style.display = 'none'; return; }

        // ✅ FIX: relative path
        const results = await apiGet(`/jobs.php?action=autocomplete&q=${encodeURIComponent(q)}`);
        if (Array.isArray(results) && results.length) {
            list.style.display = 'block';
            results.forEach(title => {
                const li = document.createElement('li');
                li.textContent  = title;
                li.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #eee;list-style:none;background:white;';
                li.addEventListener('mouseenter', () => li.style.background = '#f0f4ff');
                li.addEventListener('mouseleave', () => li.style.background = 'white');
                li.onclick = () => { input.value = title; list.style.display = 'none'; };
                list.appendChild(li);
            });
        } else {
            list.style.display = 'none';
        }
    });

    // Close on outside click
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !list.contains(e.target))
            list.style.display = 'none';
    });
}

// ── Applications ──────────────────────────────────────────────────────────────
async function applyToJob(jobId, resumeNote = '') {
    return apiPost('/applications.php', {
        job_id:      parseInt(jobId),  // ✅ ensure integer
        resume_note: resumeNote
    });
}

async function getMyApplications() {
    return apiGet('/applications.php?mine=1');
}

async function updateApplicationStatus(appId, status) {
    return apiPut(`/applications.php?id=${appId}`, { status });
}

// ── Saved Jobs ────────────────────────────────────────────────────────────────
async function toggleSaveJob(jobId, isSaved) {
    return isSaved
        ? apiDelete(`/saved.php?job_id=${jobId}`)
        : apiPost('/saved.php', { job_id: parseInt(jobId) });
}

// ── Render Job Card ───────────────────────────────────────────────────────────
function renderJobCard(job) {
    const closed  = job.status === 'closed';
    const company = escHtml(job.employer_name || job.company || 'JSTACK');
    const salary  = escHtml(job.salary || 'Negotiable');
    const loc     = escHtml(job.location || '');
    const cat     = escHtml(job.category || '');
    const date    = new Date(job.created_at).toLocaleDateString();

    return `
    <div class="job-card">
      <h3>${escHtml(job.title)}</h3>
      <p><strong>${company}</strong> — ${loc}</p>
      <p style="color:#0a66c2;font-weight:600;">${salary}</p>
      <p style="font-size:12px;color:#888;">${cat} · ${date}</p>
      <button onclick="location.href='${BASE}/jobs/detail.html?id=${job.id}'"
              ${closed ? 'disabled style="background:#ccc;"' : ''}>
        ${closed ? 'Closed' : 'View Details'}
      </button>
    </div>`;
}