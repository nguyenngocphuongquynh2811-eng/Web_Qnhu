/* Chatbot tư vấn khách hàng */
(function () {
  var root = document.getElementById('chatbot');

  if (!root) {
    return;
  }

  var config = JSON.parse(root.getAttribute('data-config'));
  var toggle = root.querySelector('.chatbot-toggle');
  var panel = root.querySelector('.chatbot-panel');
  var closeBtn = root.querySelector('.chatbot-close');
  var list = root.querySelector('.chatbot-messages');
  var chipsBox = root.querySelector('.chatbot-chips');
  var form = root.querySelector('.chatbot-form');
  var input = form.querySelector('input');
  var sendBtn = form.querySelector('button');

  // Lịch sử hội thoại giữ trong tab hiện tại để chuyển trang không bị mất
  var history = load('history') || [];

  function load(key) {
    try {
      return JSON.parse(sessionStorage.getItem(config.storage + '_' + key));
    } catch (e) {
      return null;
    }
  }

  function save(key, value) {
    try {
      sessionStorage.setItem(config.storage + '_' + key, JSON.stringify(value));
    } catch (e) {}
  }

  function scrollDown() {
    list.scrollTop = list.scrollHeight;
  }

  function renderMessage(msg) {
    var bubble = document.createElement('div');

    bubble.className = 'chatbot-msg ' + msg.from;
    bubble.textContent = msg.text;

    if (msg.products && msg.products.length) {
      var box = document.createElement('div');

      box.className = 'chatbot-products';

      msg.products.forEach(function (p) {
        var a = document.createElement('a');
        var img = document.createElement('img');
        var info = document.createElement('div');
        var name = document.createElement('div');

        a.className = 'chatbot-product';
        a.href = p.href;

        img.src = p.thumb;
        img.alt = '';
        img.loading = 'lazy';

        name.className = 'chatbot-product-name';
        name.textContent = p.name;
        info.appendChild(name);

        if (p.price) {
          var price = document.createElement('div');

          price.className = 'chatbot-product-price';
          price.textContent = p.special || p.price;

          if (p.special) {
            var old = document.createElement('s');

            old.textContent = p.price;
            price.appendChild(old);
          }

          info.appendChild(price);
        }

        a.appendChild(img);
        a.appendChild(info);
        box.appendChild(a);
      });

      bubble.appendChild(box);
    }

    (msg.links || []).forEach(function (link) {
      var a = document.createElement('a');

      a.className = 'chatbot-link';
      a.href = link.href;
      a.textContent = link.text + ' →';

      bubble.appendChild(document.createElement('br'));
      bubble.appendChild(a);
    });

    list.appendChild(bubble);
  }

  function renderChips(chips) {
    chipsBox.innerHTML = '';

    (chips || []).forEach(function (label) {
      var chip = document.createElement('button');

      chip.type = 'button';
      chip.className = 'chatbot-chip';
      chip.textContent = label;
      chip.addEventListener('click', function () {
        send(label);
      });

      chipsBox.appendChild(chip);
    });

    save('chips', chips || []);
  }

  function addMessage(msg) {
    history.push(msg);
    history = history.slice(-40);
    save('history', history);
    renderMessage(msg);
    scrollDown();
  }

  function showTyping() {
    var bubble = document.createElement('div');

    bubble.className = 'chatbot-msg bot chatbot-typing-wrap';
    bubble.innerHTML = '<span class="chatbot-typing"><span></span><span></span><span></span></span>';
    list.appendChild(bubble);
    scrollDown();

    return bubble;
  }

  function send(text) {
    text = (text || '').trim();

    if (!text || sendBtn.disabled) {
      return;
    }

    addMessage({ from: 'user', text: text });
    renderChips([]);

    input.value = '';
    sendBtn.disabled = true;

    var typing = showTyping();
    var body = new URLSearchParams();

    body.append('message', text);

    fetch(config.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString()
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error(res.status);
        }

        return res.json();
      })
      .then(function (json) {
        // Chờ một chút cho cảm giác tự nhiên
        setTimeout(function () {
          typing.remove();

          addMessage({ from: 'bot', text: json.text, links: json.links, products: json.products });

          if (json.after) {
            addMessage({ from: 'bot', text: json.after });
          }

          renderChips(json.chips);
          sendBtn.disabled = false;
          input.focus();
        }, 350);
      })
      .catch(function () {
        typing.remove();
        addMessage({ from: 'bot', text: config.error });
        renderChips(config.chips);
        sendBtn.disabled = false;
      });
  }

  function open() {
    panel.hidden = false;
    root.classList.add('is-open', 'is-seen');
    toggle.setAttribute('aria-expanded', 'true');
    save('open', true);
    save('seen', true);
    scrollDown();
    input.focus();
  }

  function close() {
    panel.hidden = true;
    root.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    save('open', false);
    toggle.focus();
  }

  // Khởi tạo
  if (!history.length) {
    history.push({ from: 'bot', text: config.welcome });
    save('history', history);
  }

  history.forEach(renderMessage);
  renderChips(load('chips') || config.chips);

  if (load('seen')) {
    root.classList.add('is-seen');
  }

  if (load('open')) {
    open();
  }

  toggle.addEventListener('click', function () {
    panel.hidden ? open() : close();
  });

  closeBtn.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) {
      close();
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    send(input.value);
  });
})();
