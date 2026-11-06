// 게시판은 JavaScript 없이도 동작합니다. 이 파일은 삭제 전 확인만 추가합니다.
// CSP가 인라인 스크립트를 막으므로 onsubmit 속성 대신 data-confirm을 사용합니다.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});
