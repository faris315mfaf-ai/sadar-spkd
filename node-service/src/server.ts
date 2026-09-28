import createApp from './app';
import config from './config';
import whatsappBotService from './services/WhatsAppBotService';
import pdfService from './services/PdfService';

const app = createApp();

// Start WhatsApp bot
void whatsappBotService.initialize().catch((error: unknown) => {
  console.error('WhatsApp bot failed to initialize:', error);
});

// Graceful shutdown handlers
const gracefulShutdown = async (signal: string): Promise<void> => {
  console.log(`Received ${signal}. Shutting down gracefully...`);
  
  try {
    await pdfService.close();
    console.log('PDF service closed.');
  } catch (error) {
    console.error('Error closing PDF service:', error);
  }
  
  process.exit(0);
};

process.on('SIGINT', () => gracefulShutdown('SIGINT'));
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));

// Start server
app.listen(config.port, config.host, () => {
  console.log(`Unified Service running on http://${config.host}:${config.port}`);
  console.log(`WA Bot endpoints:`);
  console.log(`  - Health: http://${config.host}:${config.port}/wa/health`);
  console.log(`  - Groups: http://${config.host}:${config.port}/wa/groups`);
  console.log(`  - Send Group: http://${config.host}:${config.port}/wa/send-group`);
  console.log(`  - Send Group Image: http://${config.host}:${config.port}/wa/send-group-image`);
  console.log(`PDF Service endpoints:`);
  console.log(`  - Health: http://${config.host}:${config.port}/pdf/health`);
  console.log(`  - Generate PDF: http://${config.host}:${config.port}/pdf/generate-pdf`);
});
