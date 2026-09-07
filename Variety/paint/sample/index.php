<main class="sample-page">
    <header class="sample-header">
        <img src="/images/sample.png" alt="" width="48" height="48">
        <h1><?= htmlspecialchars($title ?? 'PHP MVC', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <span class="sample-badge">CRUD</span>
    </header>

    <div class="sample-forms">
        <section>
            <h2>메시지 생성 <code>POST /foo</code></h2>
            <form id="foo-form">
                <label for="foo-message">메시지</label>
                <div class="sample-row">
                    <input id="foo-message" name="message" required maxlength="150" autocomplete="off">
                    <button type="submit">생성</button>
                </div>
            </form>
        </section>
        <section>
            <h2>메시지 + 코멘트 <code>POST /foobar</code></h2>
            <form id="foobar-form">
                <label for="foobar-message">메시지</label>
                <input id="foobar-message" name="fooMessage" required maxlength="150" autocomplete="off">
                <label for="foobar-comment">코멘트</label>
                <div class="sample-row">
                    <input id="foobar-comment" name="barComment" required maxlength="150" autocomplete="off">
                    <button type="submit">함께 생성</button>
                </div>
            </form>
        </section>
        <section>
            <h2>코멘트 추가 <code>POST /bar</code></h2>
            <form id="bar-form">
                <label for="bar-foo-id">메시지 ID</label>
                <input id="bar-foo-id" name="fooId" type="number" min="1" step="1" required>
                <label for="bar-comment">코멘트</label>
                <div class="sample-row">
                    <input id="bar-comment" name="comment" required maxlength="150" autocomplete="off">
                    <button type="submit">추가</button>
                </div>
            </form>
        </section>
    </div>

    <section class="sample-records" aria-labelledby="records-heading">
        <div class="sample-toolbar">
            <h2 id="records-heading">레코드 <span id="record-count">0</span></h2>
            <div class="sample-actions">
                <div class="sample-tabs" role="group" aria-label="리소스">
                    <button type="button" data-resource="foo" aria-pressed="true">메시지</button>
                    <button type="button" data-resource="bar" aria-pressed="false">코멘트</button>
                </div>
                <button class="secondary" id="refresh-records" type="button" title="목록 새로고침">새로고침</button>
            </div>
        </div>
        <p id="sample-status" role="status" aria-live="polite">불러오는 중...</p>
        <div class="sample-table-wrap">
            <table>
                <thead><tr><th scope="col">ID</th><th scope="col">내용</th><th scope="col">생성일</th><th scope="col">작업</th></tr></thead>
                <tbody id="sample-records"></tbody>
            </table>
        </div>
    </section>

    <section class="sample-result" aria-labelledby="response-heading">
        <div class="sample-toolbar">
            <h2 id="response-heading">응답</h2>
            <code id="response-status">GET /foo</code>
        </div>
        <pre id="sample-response" tabindex="0">{}</pre>
    </section>

    <dialog id="edit-dialog" aria-labelledby="edit-title">
        <form id="edit-form">
            <h2 id="edit-title">레코드 수정</h2>
            <label for="edit-value">내용</label>
            <textarea id="edit-value" required maxlength="150" rows="4"></textarea>
            <div class="sample-actions dialog-actions">
                <button type="button" class="secondary" id="cancel-edit">취소</button>
                <button type="submit">저장</button>
            </div>
            <p id="edit-status" role="status"></p>
        </form>
    </dialog>
</main>
