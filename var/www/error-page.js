(function () {
  var imageElement = document.getElementById('error-image');
  if (!imageElement) return;

  var prefix = imageElement.getAttribute('data-error-image-prefix') || '';
  var count = parseInt(imageElement.getAttribute('data-error-image-count') || '0', 10);
  if (prefix !== '' && count > 0) {
    var selected = Math.floor(Math.random() * count) + 1;
    imageElement.src = prefix + selected + '.png';
  }

  imageElement.classList.add('error-image');
  imageElement.addEventListener('error', function () {
    imageElement.style.display = 'none';
  });

  var link = document.createElement('link');
  link.rel = 'stylesheet';
  link.type = 'text/css';
  link.href = '/css/error-styles.css';
  document.head.appendChild(link);
}());
