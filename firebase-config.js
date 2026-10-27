import { initializeApp } from "firebase/app";
import { getAuth } from "firebase/auth";

const firebaseConfig = {
  apiKey: "AIzaSyD4ojTWTe_D4FxTFH0sb1rBNuMr50J4sNQ",
  authDomain: "kenji-s-kitchen-otp.firebaseapp.com",
  projectId: "kenji-s-kitchen-otp",
  storageBucket: "kenji-s-kitchen-otp.firebasestorage.app",
  messagingSenderId: "358685938780",
  appId: "1:358685938780:web:10a7045f0dc6d798608299",
  measurementId: "G-Q8FTX539VK"
};

const app = initializeApp(firebaseConfig);
export const auth = getAuth(app);
