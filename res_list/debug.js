// Debug Script - In Browser Console ausführen
console.clear();
console.log('🔍 Debug Mode aktiviert');

// 1. Module Check
console.group('📦 Module Status');
console.log('Utils:', typeof Utils !== 'undefined' ? '✅' : '❌');
console.log('dataManager:', typeof dataManager !== 'undefined' ? '✅' : '❌');
console.log('tableManager:', typeof tableManager !== 'undefined' ? '✅' : '❌');
console.log('eventManager:', typeof eventManager !== 'undefined' ? '✅' : '❌');
console.log('app:', typeof app !== 'undefined' ? '✅' : '❌');
console.groupEnd();

// 2. DOM Elements Check
console.group('🎯 DOM Elements');
const tbody = document.getElementById('reservations-tbody');
console.log('tbody gefunden:', !!tbody);
if (tbody) console.log('tbody innerHTML:', tbody.innerHTML.substring(0, 100));
console.groupEnd();

// 3. Daten manuell laden
console.group('📡 Manueller Datenload');
if (typeof dataManager !== 'undefined') {
    console.log('Lade Daten...');
    dataManager.loadReservations({ date: '2025-08-31' })
        .then(data => console.log('✅ Daten geladen:', data))
        .catch(err => console.error('❌ Fehler:', err));
} else {
    console.error('dataManager nicht verfügbar');
}
console.groupEnd();

// 4. API Test
console.group('🧪 API Test');
fetch('api/test-simple.php?date=2025-08-31')
    .then(response => response.json())
    .then(data => {
        console.log('API Antwort:', data);
        console.log('Anzahl Datensätze:', data.count);
    })
    .catch(err => console.error('API Fehler:', err));
console.groupEnd();
