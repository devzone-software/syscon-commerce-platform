package pe.syscon.auth.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.auth.domain.User;
public interface UserRepository extends JpaRepository<User,UUID> { Optional<User> findByEmail(String email); }
