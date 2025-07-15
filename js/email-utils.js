/**
 * Email Utilities für Reservierungen
 * Erstellt standardisierte Emails für Gäste mit Buchungslinks
 */

window.EmailUtils = {
  /**
   * Erstellt und öffnet eine Email für die Namensliste-Anfrage
   * @param {Object} reservationData - Reservierungsdaten
   * @param {string} reservationData.id - Reservierungs-ID
   * @param {string} reservationData.nachname - Nachname
   * @param {string} reservationData.vorname - Vorname
   * @param {string} reservationData.email - Email-Adresse (optional)
   * @param {string} reservationData.anreise - Anreise-Datum
   * @param {string} reservationData.abreise - Abreise-Datum
   */
  async sendNameListEmail(reservationData) {
    const { id, nachname = '', vorname = '', email = '', anreise = '', abreise = '' } = reservationData;
    
    // Datum formatieren (für deutsche Anzeige)
    const fmtDate = iso => {
      if (!iso) return '';
      const [y, m, d] = iso.split('T')[0].split('-');
      return `${d}.${m}.`;
    };
    
    const arrivalDate = fmtDate(anreise);
    const departureDate = fmtDate(abreise);
    
    try {
      // Get booking URL
      const bookingResponse = await fetch(`getBookingUrl.php?id=${id}`);
      const bookingData = await bookingResponse.json();
      
      let bookingLink = '';
      if (bookingData.url) {
        bookingLink = bookingData.url;
      } else {
        bookingLink = 'https://booking.franzsennhuette.at'; // Fallback
      }
      
      // Create email subject and body
      const subject = `Namensliste für Ihre Reservierung - Name List for Your Reservation`;
      
      const body = `Hallo!

Vielen Dank für Ihre Reservierung vom ${arrivalDate} bis ${departureDate}.

Bitte geben Sie die Namen aller Teilnehmer / Mitreisenden in unserem Online-Formular ein:
${bookingLink}

Alternativ können Sie uns die Namensliste auch per E-Mail zurücksenden - wir tragen sie dann gerne für Sie ein.

Mit freundlichen Grüßen
Franz-Senn-Hütte
office@franzsennhuette.at

---

Hello!

Thank you for your reservation from ${arrivalDate} to ${departureDate}.

Please enter the names of all participants / fellow travelers in our online form:
${bookingLink}

Alternatively, you can send us the name list by email - we will be happy to enter it for you.

Best regards
Franz-Senn-Hütte
office@franzsennhuette.at`;
      
      // Create mailto link
      let mailtoLink;
      if (email && email.trim() !== '') {
        mailtoLink = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      } else {
        mailtoLink = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        
        // Show info that no email address is available
        setTimeout(() => {
          alert('Hinweis: Für diese Reservierung ist keine E-Mail-Adresse hinterlegt.\nBitte geben Sie die E-Mail-Adresse manuell ein.');
        }, 100);
      }
      
      // Open email client
      window.location.href = mailtoLink;
      
    } catch (error) {
      console.error('Error fetching booking URL:', error);
      
      // Fallback email without booking link
      const subject = `Namensliste für Ihre Reservierung - Name List for Your Reservation`;
      const body = `Hallo!

Vielen Dank für Ihre Reservierung vom ${arrivalDate} bis ${departureDate}.

Bitte senden Sie uns die Namen aller Teilnehmer / Mitreisenden per E-Mail zu - wir tragen sie gerne für Sie ein.

Mit freundlichen Grüßen
Franz-Senn-Hütte
office@franzsennhuette.at

---

Hello!

Thank you for your reservation from ${arrivalDate} to ${departureDate}.

Please send us the names of all participants / fellow travelers by email - we will be happy to enter them for you.

Best regards
Franz-Senn-Hütte
office@franzsennhuette.at`;
      
      let mailtoLink;
      if (email && email.trim() !== '') {
        mailtoLink = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
      } else {
        mailtoLink = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        setTimeout(() => {
          alert('Hinweis: Für diese Reservierung ist keine E-Mail-Adresse hinterlegt.\nBitte geben Sie die E-Mail-Adresse manuell ein.');
        }, 100);
      }
      
      window.location.href = mailtoLink;
    }
  }
};
