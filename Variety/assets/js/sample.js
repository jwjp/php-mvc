const responseBox = document.querySelector('#sample-response');
const statusBox = document.querySelector('#sample-status');
const recordsBody = document.querySelector('#sample-records');
const dialog = document.querySelector('#edit-dialog');
const token = document.querySelector('meta[name="csrf-token"]').content;
let resource = 'foo';
let editing = null;
let busy = false;

function status(message, error = false) {
    statusBox.textContent = message;
    statusBox.classList.toggle('error', error);
}

async function request(path, options = {}, display = true) {
    const method = options.method || 'GET';
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (method !== 'GET') headers.set('X-CSRF-Token', token);
    const response = await fetch(path, {
        ...options,
        headers,
        signal: AbortSignal.timeout(15000),
    });
    const text = await response.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        throw new Error('JSON 응답을 읽을 수 없습니다. 서버 상태를 확인하세요.');
    }
    if (display || !response.ok) {
        responseBox.textContent = JSON.stringify(data, null, 2);
        document.querySelector('#response-status').textContent = method + ' ' + path + ' · ' + response.status;
    }
    if (!response.ok) throw new Error(data.message || '요청에 실패했습니다.');
    return data;
}

async function run(task) {
    if (busy) return;
    busy = true;
    document.querySelectorAll('button').forEach(button => { button.disabled = true; });
    document.querySelector('main').setAttribute('aria-busy', 'true');
    status('처리 중...');
    document.querySelector('#edit-status').textContent = '';
    try {
        await task();
    } catch (error) {
        const message = error.name === 'TimeoutError'
            ? '응답 시간이 초과되었습니다. 새로고침하여 결과를 확인하세요.'
            : error.message || '서버에 연결할 수 없습니다.';
        status(message, true);
        if (dialog.open) document.querySelector('#edit-status').textContent = message;
    } finally {
        busy = false;
        document.querySelectorAll('button').forEach(button => { button.disabled = false; });
        document.querySelector('main').setAttribute('aria-busy', 'false');
    }
}

function actionButton(text, onClick, className = 'secondary') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = text;
    button.disabled = busy;
    button.addEventListener('click', onClick);
    return button;
}

function render(rows) {
    recordsBody.replaceChildren();
    document.querySelector('#record-count').textContent = rows.length;
    for (const row of rows) {
        const tr = document.createElement('tr');
        for (const value of [
            row.id,
            resource === 'foo' ? row.message : '#' + row.foo_id + ' · ' + row.comment,
            row.created_at,
        ]) {
            const cell = document.createElement('td');
            cell.textContent = value;
            tr.append(cell);
        }
        const actions = document.createElement('td');
        const group = document.createElement('div');
        group.className = 'sample-actions';
        group.append(actionButton('수정', () => {
            editing = { resource, id: row.id };
            document.querySelector('#edit-title').textContent = (resource === 'foo' ? '메시지' : '코멘트') + ' #' + row.id;
            document.querySelector('#edit-value').value = resource === 'foo' ? row.message : row.comment;
            document.querySelector('#edit-status').textContent = '';
            dialog.showModal();
        }));
        if (resource === 'foo') {
            group.append(actionButton('코멘트', () => {
                document.querySelector('#bar-foo-id').value = row.id;
                document.querySelector('#bar-comment').focus();
            }));
        }
        group.append(actionButton('삭제', () => {
            const target = resource;
            const message = target === 'foo'
                ? '메시지와 연결된 코멘트를 모두 삭제할까요?'
                : '이 코멘트를 삭제할까요?';
            if (!window.confirm(message)) return;
            run(async () => {
                const count = await request('/' + target + '/' + row.id, { method: 'DELETE' });
                await refresh(false);
                status(count ? '삭제했습니다.' : '이미 삭제된 레코드입니다.');
            });
        }, 'danger'));
        actions.append(group);
        tr.append(actions);
        recordsBody.append(tr);
    }
    if (rows.length === 0) {
        const cell = document.createElement('td');
        cell.colSpan = 4;
        cell.className = 'empty';
        cell.textContent = '등록된 레코드가 없습니다.';
        const tr = document.createElement('tr');
        tr.append(cell);
        recordsBody.append(tr);
    }
}

async function refresh(display = true) {
    const rows = await request('/' + resource, {}, display);
    if (!Array.isArray(rows)) throw new Error('목록 응답 형식이 올바르지 않습니다.');
    render(rows);
    status(rows.length + '개 레코드');
}

for (const [id, path] of [['foo-form', '/foo'], ['foobar-form', '/foobar'], ['bar-form', '/bar']]) {
    document.getElementById(id).addEventListener('submit', event => {
        event.preventDefault();
        const form = event.currentTarget;
        const body = new FormData(form);
        run(async () => {
            const result = await request(path, { method: 'POST', body });
            if (path === '/foo') document.querySelector('#bar-foo-id').value = result;
            form.reset();
            await refresh(false);
            status('생성했습니다.');
        });
    });
}

document.querySelectorAll('[data-resource]').forEach(button => {
    button.addEventListener('click', () => run(async () => {
        const nextResource = button.dataset.resource;
        const rows = await request('/' + nextResource);
        if (!Array.isArray(rows)) throw new Error('목록 응답 형식이 올바르지 않습니다.');
        resource = nextResource;
        document.querySelectorAll('[data-resource]').forEach(tab => {
            tab.setAttribute('aria-pressed', String(tab.dataset.resource === resource));
        });
        render(rows);
        status(rows.length + '개 레코드');
    }));
});
document.querySelector('#refresh-records').addEventListener('click', () => run(refresh));
document.querySelector('#cancel-edit').addEventListener('click', () => dialog.close());
dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
document.querySelector('#edit-form').addEventListener('submit', event => {
    event.preventDefault();
    const field = editing.resource === 'foo' ? 'message' : 'comment';
    const value = document.querySelector('#edit-value').value;
    run(async () => {
        await request('/' + editing.resource + '/' + editing.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        dialog.close();
        await refresh(false);
        status('저장했습니다.');
    });
});
run(refresh);
