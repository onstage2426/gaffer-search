import '../../css/admin.css'
import Alpine from 'alpinejs'

// "Set" (non-empty) check, used across the component methods below.
const set = (v) => v !== '' && v !== null && v !== undefined

// POSTs to admin-ajax.php with the plugin's nonce attached. Array-valued params are
// appended as repeated keys (e.g. { 'facets[]': ['a', 'b'] }); empty/null/undefined
// scalar values are omitted entirely — the same "don't send it at all" behavior every
// call site used to implement by hand before appending to its own URLSearchParams.
async function postAction(action, params = {}) {
    const body = new URLSearchParams({ action, _ajax_nonce: gafferSearch.nonce })
    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            value.forEach(v => body.append(key, v))
        } else if (set(value)) {
            body.append(key, value)
        }
    }
    return fetch(gafferSearch.ajaxUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    }).then(r => r.json())
}

const registerComponents = (a) => {
a.data('gafferSearchAdmin', () => ({
    tab: location.hash.slice(1) || 'indexes',

    // The search endpoint's health check (?check=<site>): ok when PHP runs at its URL and it
    // finds this site's config, `missing` when it doesn't, anything else (a 403/404 page, no
    // JSON) means the server doesn't let PHP run there.
    endpointCheck: { status: null, message: '' },

    status: '',
    statusType: '',

    init() {
        this.checkEndpoint()
    },

    async checkEndpoint() {
        const url = `${gafferSearch.searchUrl}?check=${gafferSearch.siteId}`
        try {
            const r = await fetch(url, { cache: 'no-store' })
            let body = null
            try { body = await r.json() } catch {}
            if (body?.ok === true) {
                this.endpointCheck = { status: 'ok', message: '' }
            } else if (body?.code === 'config_missing') {
                this.endpointCheck = { status: 'missing', message: '' }
            } else {
                this.endpointCheck = { status: 'error', message: `${url} → HTTP ${r.status}` }
            }
        } catch {
            this.endpointCheck = { status: 'error', message: `${url} → no response` }
        }
    },

    setTab(name) {
        this.tab = name
        history.replaceState(null, '', '#' + name)
    },

    async runSearch(name, q, state) {
        if (!q.trim()) return

        state.loading = true
        state.results = null
        state.error   = null

        try {
            const data = await postAction('gaffer_search', { index_name: name, q })

            if (data.success) {
                state.results = data.data
            } else {
                state.error = data.data || 'Unknown error.'
            }
        } catch {
            state.error = 'Request failed.'
        }

        state.loading = false
    },

    async rebuild(indexName) {
        this.status     = `Building "${indexName}"…`
        this.statusType = 'loading'

        // A poll still in flight when the build ends must not schedule the next one (or overwrite
        // the final status): clearTimeout() alone misses it.
        let building  = true
        let pollTimer = null
        const pollProgress = async () => {
            try {
                const r = await postAction('gaffer_search_progress', { index_name: indexName })
                if (building && r.success && r.data.count !== null) {
                    this.status = `Building "${indexName}"… ${r.data.count.toLocaleString()} docs indexed`
                }
            } catch {}
            if (building) pollTimer = setTimeout(pollProgress, 1500)
        }
        pollTimer = setTimeout(pollProgress, 1500)
        const stopPolling = () => {
            building = false
            clearTimeout(pollTimer)
        }

        try {
            const data = await postAction('gaffer_search_rebuild', { index_name: indexName })

            stopPolling()

            if (data.success) {
                this.status     = 'Done — ' + Object.entries(data.data.counts).map(([n, c]) => `${n}: ${c}`).join(', ')
                this.statusType = 'success'
                setTimeout(() => location.reload(), 1500)
            } else {
                this.status     = 'Error: ' + (data.data || 'unknown error')
                this.statusType = 'error'
            }
        } catch {
            stopPolling()
            this.status     = 'Request failed.'
            this.statusType = 'error'
        }
    },

    async rotateSecret(type) {
        const msg = type === 'search'
            ? 'Rotating the search secret makes ALL existing index files inaccessible.\n\nA rebuild of every index is scheduled right away; until it finishes, searches return no results. The old files appear as orphans on the Indexes tab and are safe to delete.\n\nThis cannot be undone.'
            : 'Rotating the token signing secret invalidates ALL existing signed tokens.\n\nEvery page cached with an old token (WP Rocket, a CDN, any full-page cache) fails its searches until that cache is purged — purge it right after rotating. Visitors with a page already open see an error until they refresh.\n\nNew pages get tokens signed with the new secret; only cached ones break.'

        if (!confirm(msg + '\n\nContinue?')) return

        try {
            const r = await postAction(type === 'search' ? 'gaffer_search_rotate_search_secret' : 'gaffer_search_rotate_token_secret')

            if (r.success) location.reload()
            else alert('Error: ' + (r.data || 'unknown error'))
        } catch {
            alert('Request failed.')
        }
    },

    async cleanupTemp() {
        this.status     = 'Removing leftover temp files…'
        this.statusType = 'loading'
        try {
            const r = await postAction('gaffer_search_cleanup_temp')
            if (r.success) {
                const n = r.data.deleted.length
                this.status     = n ? `Removed ${n} file(s): ${r.data.deleted.join(', ')}` : 'No leftover temp files.'
                this.statusType = 'success'
            } else {
                this.status     = 'Error: ' + (r.data || 'unknown error')
                this.statusType = 'error'
            }
        } catch {
            this.status     = 'Request failed.'
            this.statusType = 'error'
        }
    },

    async verifyIndex(name, state) {
        state.loading  = true
        state.result   = null
        state.error    = null
        state.progress = null

        let running   = true
        let pollTimer = null
        const poll = async () => {
            try {
                const r = await postAction('gaffer_search_progress', { index_name: name, what: 'verify' })
                if (running && r.success && r.data.count !== null) state.progress = r.data
            } catch {}
            if (running) pollTimer = setTimeout(poll, 1000)
        }
        pollTimer = setTimeout(poll, 1000)

        try {
            const r = await postAction('gaffer_search_verify', { index_name: name })
            if (r.success) state.result = r.data
            else state.error = r.data || 'Unknown error.'
        } catch {
            state.error = 'Request failed.'
        }
        running = false
        clearTimeout(pollTimer)
        state.loading = false
    },

    async resetIndex(name, orphan = false) {
        if (!confirm(`Reset index "${name}"? The .db files will be removed and the index must be rebuilt.`)) return

        try {
            const data = await postAction('gaffer_search_delete_index', {
                index_name: name,
                orphan:     orphan ? '1' : undefined,
            })

            if (data.success) location.reload()
            else alert('Error: ' + (data.data || 'unknown error'))
        } catch {
            alert('Request failed.')
        }
    }
}))
}

if (window.Alpine) {
    registerComponents(window.Alpine)
} else {
    document.addEventListener('alpine:init', () => registerComponents(Alpine))
    window.Alpine = Alpine
    Alpine.start()
}
