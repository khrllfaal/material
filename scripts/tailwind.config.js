const path = require('path');
module.exports = {
  content: [path.join(__dirname, '..', 'frontend', '*.html')],
  safelist: [
    'bg-emerald-50','text-emerald-600','bg-emerald-600','hover:bg-emerald-700',
    'bg-amber-50','text-amber-600','bg-amber-600','hover:bg-amber-700',
  ],
  theme: { extend: {} },
  plugins: [],
};
