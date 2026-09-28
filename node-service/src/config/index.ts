import dotenv from 'dotenv';

dotenv.config();

interface Config {
  port: number;
  host: string;
  botToken: string | undefined;
  authToken: string | undefined;
  nodeEnv: string;
}

const config: Config = {
  port: parseInt(process.env.PORT || '3000', 10),
  // 127.0.0.1 on a plain server; 0.0.0.0 inside Docker so other containers can reach it.
  host: process.env.HOST || '127.0.0.1',
  botToken: process.env.BOT_TOKEN,
  authToken: process.env.AUTH_TOKEN,
  nodeEnv: process.env.NODE_ENV || 'development',
};

export default config;
