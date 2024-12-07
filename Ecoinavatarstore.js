// ecoinavatarstore.js
document.addEventListener('DOMContentLoaded', function() {
    const buttons = document.querySelectorAll('.buy-avatar-frame');

    buttons.forEach(function(button) {
        button.addEventListener('click', function() {
            const frameId = this.getAttribute('data-frame-id');
            if (confirm('确定要购买此头像框吗？')) {
                // 发送 AJAX 请求
                fetch(ecoinAvatarStoreAjax.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=buy_avatar_frame&frame_id=${frameId}&csrf_token=${encodeURIComponent(ecoinAvatarStoreAjax.nonce)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload(); // 刷新页面以显示更新后的信息
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('购买过程中出现错误，请稍后再试。');
                });
            }
        });
    });
});
